/*
 * ESP32 RFID Gate Automation System - RC522 + Two-Gate Support
 * 
 * Hardware:
 * - ESP32 Development Board
 * - RC522 RFID Module (SPI)
 * - 1x Relay Module (for gate motor) PER GATE
 * 
 * Connections (typical RC522 wiring):
 * RC522 (SPI Mode):
 *   3.3V  -> 3.3V
 *   GND   -> GND
 *   RST   -> GPIO 22
 *   SDA(SS)-> GPIO 5
 *   MOSI  -> GPIO 23
 *   MISO  -> GPIO 19
 *   SCK   -> GPIO 18
 * 
 * Relay (Gate Motor):
 *   IN -> GPIO 2
 *   VCC -> 5V
 *   GND -> GND
 * 
 * Logic:
 * - You will FLASH THIS SKETCH TWICE (once per ESP32):
 *   - ENTRANCE GATE: GATE_TYPE = "checkin"
 *   - EXIT GATE:     GATE_TYPE = "checkout"
 * - When RFID card is scanned:
 *   - ENTRANCE (checkin): Calls /api/gate.php?type=checkin&uid=XXXX
 *   - EXIT (checkout):    Calls /api/gate.php?type=checkout&uid=XXXX
 * - The server:
 *   - Checks fee defaulter status at ENTRANCE
 *   - Ensures first scan of the day is marked
 *   - Uses is_checked_in flag to allow/deny exit
 * 
 * Libraries Required:
 * - WiFi (built-in)
 * - HTTPClient (built-in)
 * - MFRC522 (install from Library Manager: "MFRC522")
 * - SPI (built-in)
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>

// ============================================
// CONFIGURATION - UPDATE THESE VALUES
// ============================================
const char* ssid = "YOUR_WIFI_SSID";
const char* password = "YOUR_WIFI_PASSWORD";
const char* serverURL = "http://your-domain.com/gym-management"; // Update with your server URL (same as website)

// GATE TYPE:
//   "checkin"  -> Entrance gate (only checks in, sets flag = 1)
//   "checkout" -> Exit gate (only checks out, sets flag = 0)
const char* GATE_TYPE = "checkin";   // CHANGE to "checkout" when flashing for exit gate

// Optional gate name to send (for logging / future use)
const char* GATE_NAME = "entrance";  // For exit ESP, set this to "exit"

// RC522 Configuration (SPI Mode)
#define RC522_SCK   (18)
#define RC522_MOSI  (23)
#define RC522_MISO  (19)
#define RC522_SS    (5)   // RFID SS/CS pin
#define RC522_RST   (22)  // RFID RST pin

// Relay Pin (Single Gate Motor)
#define RELAY_GATE  2

// Gate Control Timing
#define GATE_OPEN_TIME 3000  // Time to keep gate open (milliseconds)

// ============================================
// GLOBAL VARIABLES
// ============================================
MFRC522 mfrc522(RC522_SS, RC522_RST);  // RC522 RFID reader

String lastUID = "";
unsigned long lastReadTime = 0;
unsigned long gateOpenTime = 0;
bool gateOpen = false;

// ============================================
// SETUP
// ============================================
void setup() {
    Serial.begin(115200);
    delay(1000);
    
    Serial.println("\n\n=================================");
    Serial.println("ESP32 RFID Gate - RC522");
    Serial.print("Gate type: ");
    Serial.println(GATE_TYPE);
    Serial.println("=================================\n");
    
    // Initialize relay pin
    pinMode(RELAY_GATE, OUTPUT);
    digitalWrite(RELAY_GATE, LOW);
    
    // Initialize RC522 reader
    initReader();
    
    // Connect to WiFi
    connectWiFi();
    
    Serial.println("\nSystem ready! Waiting for NFC cards...\n");
}

// ============================================
// MAIN LOOP
// ============================================
void loop() {
    // Check WiFi connection
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("WiFi disconnected. Reconnecting...");
        connectWiFi();
    }
    
    // Handle gate closing (auto-close after timeout)
    if (gateOpen && (millis() - gateOpenTime > GATE_OPEN_TIME)) {
        closeGate();
    }
    
    // Poll for admin force-open commands (every 1 second)
    static unsigned long lastPollTime = 0;
    if (millis() - lastPollTime > 1000) {
        lastPollTime = millis();
        checkForceOpenCommand();
    }
    
    // Read from NFC reader
    pollReader();
    
    delay(50); // Small delay to prevent excessive polling
}

// ============================================
// WIFI CONNECTION
// ============================================
void connectWiFi() {
    Serial.print("Connecting to WiFi: ");
    Serial.println(ssid);
    
    WiFi.mode(WIFI_STA);
    WiFi.begin(ssid, password);
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
        delay(500);
        Serial.print(".");
        attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
        Serial.println("\nWiFi connected!");
        Serial.print("IP address: ");
        Serial.println(WiFi.localIP());
    } else {
        Serial.println("\nWiFi connection failed!");
    }
}

// ============================================
// PROCESS RFID CARD (Check-in OR Check-out depending on GATE_TYPE)
// ============================================
bool processRFID(String uid) {
    HTTPClient http;
    String url = String(serverURL) + "/api/gate.php?uid=" + uid + "&type=" + String(GATE_TYPE) + "&gate=" + String(GATE_NAME);
    
    Serial.print("Sending request to: ");
    Serial.println(url);
    
    http.begin(url);
    http.setTimeout(5000);
    int httpCode = http.GET();
    
    if (httpCode > 0) {
        String payload = http.getString();
        Serial.print("Response code: ");
        Serial.println(httpCode);
        Serial.print("Response: ");
        Serial.println(payload);
        
        // Parse JSON response (simple parsing)
        if (payload.indexOf("\"allowed\":true") > 0 || payload.indexOf("'allowed':true") > 0) {
            Serial.println("✓ Access granted!");
            
            // No need to parse action here; server ensures correct behavior

            openGate();
            http.end();
            return true;
        } else {
            Serial.println("✗ Access denied");
            http.end();
            return false;
        }
    } else {
        Serial.print("HTTP Error: ");
        Serial.println(httpCode);
        http.end();
        return false;
    }
}

// ============================================
// RC522 HELPERS
// ============================================
void initReader() {
    Serial.println("Initializing RC522 RFID Reader...");

    SPI.begin(RC522_SCK, RC522_MISO, RC522_MOSI, RC522_SS);
    mfrc522.PCD_Init();
    delay(50);
    Serial.println("RC522 configured!\n");
}

void pollReader() {
    // Look for new RFID cards
    if (!mfrc522.PICC_IsNewCardPresent() || !mfrc522.PICC_ReadCardSerial()) {
        return;
    }

    // Build UID string (HEX, no spaces, uppercase)
    String uidString = "";
    for (byte i = 0; i < mfrc522.uid.size; i++) {
        if (mfrc522.uid.uidByte[i] < 0x10) uidString += "0";
        uidString += String(mfrc522.uid.uidByte[i], HEX);
    }
    uidString.toUpperCase();
    
    // Debounce: prevent same card from being read multiple times
    if (uidString == lastUID && (millis() - lastReadTime < 2000)) return;
    lastUID = uidString;
    lastReadTime = millis();
    
    Serial.print("RFID card detected: ");
    Serial.println(uidString);
    
    processRFID(uidString);

    // Halt PICC and stop encryption to prepare for next read
    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();
}

// ============================================
// CHECK FOR FORCE-OPEN COMMAND
// ============================================
void checkForceOpenCommand() {
    HTTPClient http;
    String url = String(serverURL) + "/api/gate.php?type=poll";
    
    http.begin(url);
    http.setTimeout(2000);
    int httpCode = http.GET();
    
    if (httpCode > 0) {
        String payload = http.getString();
        
        // Parse response
        if (payload.indexOf("\"command\":\"open\"") > 0 || payload.indexOf("'command':'open'") > 0) {
            Serial.println("Admin force-open: Gate");
            openGate();
        }
    }
    
    http.end();
}

// ============================================
// GATE CONTROL
// ============================================
void openGate() {
    digitalWrite(RELAY_GATE, HIGH);
    Serial.println("✓ Gate OPENED");
    gateOpen = true;
    gateOpenTime = millis();
}

void closeGate() {
    digitalWrite(RELAY_GATE, LOW);
    Serial.println("Gate CLOSED");
    gateOpen = false;
}

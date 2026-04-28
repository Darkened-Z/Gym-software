/*
 * ESP32 RFID Admin Scanner - Assignment Mode
 * 
 * Hardware:
 * - ESP32 Development Board
 * - RC522 RFID Module (SPI)
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
 * Logic:
 * - Scans RFID card.
 * - Sends HTTP GET to /api/rfid-assign.php?action=scan&uid=XXXX
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
const char* serverURL = "http://your-domain.com/gym-management"; // Update with your server URL

// RC522 Configuration (SPI Mode)
#define RC522_SCK   (18)
#define RC522_MOSI  (23)
#define RC522_MISO  (19)
#define RC522_SS    (5)   // RFID SS/CS pin
#define RC522_RST   (22)  // RFID RST pin

// ============================================
// GLOBAL VARIABLES
// ============================================
MFRC522 mfrc522(RC522_SS, RC522_RST);  // RC522 RFID reader
String lastUID = "";
unsigned long lastReadTime = 0;

// ============================================
// SETUP
// ============================================
void setup() {
    Serial.begin(115200);
    delay(1000);
    
    Serial.println("\n\n=================================");
    Serial.println("ESP32 RFID Admin Scanner");
    Serial.println("=================================\n");
    
    // Initialize RC522 reader
    SPI.begin(RC522_SCK, RC522_MISO, RC522_MOSI, RC522_SS);
    mfrc522.PCD_Init();
    delay(50);
    Serial.println("RC522 configured!");
    
    // Connect to WiFi
    connectWiFi();
    
    Serial.println("\nSystem ready! Waiting for NFC cards to assign...\n");
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
    
    // Read from NFC reader
    if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
        
        // Build UID string (HEX, no spaces, uppercase)
        String uidString = "";
        for (byte i = 0; i < mfrc522.uid.size; i++) {
            if (mfrc522.uid.uidByte[i] < 0x10) uidString += "0";
            uidString += String(mfrc522.uid.uidByte[i], HEX);
        }
        uidString.toUpperCase();
        
        // Debounce: prevent same card from being read multiple times
        if (uidString != lastUID || (millis() - lastReadTime > 2000)) {
            lastUID = uidString;
            lastReadTime = millis();
            
            Serial.print("RFID card detected: ");
            Serial.println(uidString);
            
            sendScanToServer(uidString);
        }

        // Halt PICC and stop encryption to prepare for next read
        mfrc522.PICC_HaltA();
        mfrc522.PCD_StopCrypto1();
    }
    
    delay(50); 
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
// SEND RFID TO SERVER
// ============================================
void sendScanToServer(String uid) {
    HTTPClient http;
    String url = String(serverURL) + "/api/rfid-assign.php?action=scan&uid=" + uid;
    
    Serial.print("Sending UID to: ");
    Serial.println(url);
    
    http.begin(url);
    http.setTimeout(5000);
    int httpCode = http.GET();
    
    if (httpCode > 0) {
        String payload = http.getString();
        Serial.print("Response code: ");
        Serial.println(httpCode);
        Serial.println(payload);
    } else {
        Serial.print("HTTP Error: ");
        Serial.println(httpCode);
    }
    
    http.end();
}

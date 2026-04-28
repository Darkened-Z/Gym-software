/*
 * Production ESP32 Exit Gate Firmware
 * Version: 2.0 - Production Hardened
 * 
 * Features:
 * - WiFi auto-reconnect
 * - API timeout handling (10s)
 * - Duplicate scan suppression (5s cooldown)
 * - Fail-safe relay control
 * - Health check on startup
 * - Comprehensive error handling
 * - Duration display
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>

// ============================================================================
// CONFIGURATION - Update these for your setup
// ============================================================================

const char* WIFI_SSID = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char* SERVER_URL = "http://YOUR_SERVER_IP/gym-management/api/gate.php";
const char* GATE_ID = "EXIT_01";  // EXIT GATE

// Hardware pins
#define SS_PIN 5
#define RST_PIN 22
#define RELAY_PIN 4
#define LED_PIN 2
#define BUZZER_PIN 15  // Optional

// Timing configuration (from server .env)
#define SCAN_COOLDOWN_MS 5000      // 5 seconds
#define API_TIMEOUT_MS 10000        // 10 seconds
#define GATE_OPEN_DURATION_MS 3000  // 3 seconds
#define WIFI_RECONNECT_DELAY_MS 500
#define MAX_WIFI_ATTEMPTS 20

// Debug mode
#define DEBUG_MODE true

// ============================================================================
// GLOBAL VARIABLES
// ============================================================================

MFRC522 rfid(SS_PIN, RST_PIN);
unsigned long lastScanTime = 0;
String lastScannedUID = "";
int consecutiveFailures = 0;

// ============================================================================
// SETUP
// ============================================================================

void setup() {
    Serial.begin(115200);
    Serial.println("\n\n=================================");
    Serial.println("GYM EXIT GATE - PRODUCTION v2.0");
    Serial.println("=================================\n");
    
    // Initialize hardware
    pinMode(RELAY_PIN, OUTPUT);
    pinMode(LED_PIN, OUTPUT);
    pinMode(BUZZER_PIN, OUTPUT);
    digitalWrite(RELAY_PIN, LOW);  // Gate closed
    digitalWrite(LED_PIN, LOW);
    digitalWrite(BUZZER_PIN, LOW);
    
    // Initialize RFID
    SPI.begin();
    rfid.PCD_Init();
    Serial.println("[OK] RFID RC522 initialized");
    
    // Connect to WiFi
    connectWiFi();
    
    // Health check
    if (checkServerHealth()) {
        Serial.println("[OK] Server health check passed");
        blinkSuccess();
    } else {
        Serial.println("[WARN] Server health check failed - continuing anyway");
        blinkWarning();
    }
    
    Serial.println("\n[READY] Waiting for RFID scans...\n");
}

// ============================================================================
// MAIN LOOP
// ============================================================================

void loop() {
    // WiFi watchdog
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[ERROR] WiFi disconnected!");
        blinkError();
        reconnectWiFi();
        return;
    }
    
    // Check for new card
    if (!rfid.PICC_IsNewCardPresent()) {
        delay(50);
        return;
    }
    
    if (!rfid.PICC_ReadCardSerial()) {
        delay(50);
        return;
    }
    
    // Read UID
    String rfidUID = getRFIDString();
    unsigned long currentTime = millis();
    
    // Cooldown check - prevent duplicate scans
    if (rfidUID == lastScannedUID && (currentTime - lastScanTime) < SCAN_COOLDOWN_MS) {
        if (DEBUG_MODE) {
            Serial.println("[COOLDOWN] Same card scanned within cooldown window - ignored");
        }
        rfid.PICC_HaltA();
        rfid.PCD_StopCrypto1();
        return;
    }
    
    lastScannedUID = rfidUID;
    lastScanTime = currentTime;
    
    Serial.println("========================================");
    Serial.print("[SCAN] RFID UID: ");
    Serial.println(rfidUID);
    Serial.print("[INFO] Time: ");
    Serial.println(getTimestamp());
    
    // Process exit request
    bool success = processExit(rfidUID);
    
    if (success) {
        consecutiveFailures = 0;
        openGate();
        blinkSuccess();
    } else {
        consecutiveFailures++;
        blinkError();
        
        // If too many consecutive failures, check server health
        if (consecutiveFailures >= 3) {
            Serial.println("[WARN] Multiple failures - checking server...");
            if (!checkServerHealth()) {
                Serial.println("[ERROR] Server unreachable!");
                blinkWarning();
            }
            consecutiveFailures = 0;
        }
    }
    
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
    delay(1000);  // Brief delay before next scan
}

// ============================================================================
// CORE FUNCTIONS
// ============================================================================

bool processExit(String rfidUID) {
    HTTPClient http;
    http.setTimeout(API_TIMEOUT_MS);
    
    String url = String(SERVER_URL) + "?type=exit&rfid_uid=" + rfidUID + "&gate_id=" + GATE_ID;
    
    if (DEBUG_MODE) {
        Serial.print("[API] Calling: ");
        Serial.println(url);
    }
    
    http.begin(url);
    int httpCode = http.GET();
    
    if (httpCode != 200) {
        Serial.print("[ERROR] HTTP ");
        Serial.println(httpCode);
        http.end();
        return false;
    }
    
    String response = http.getString();
    http.end();
    
    if (DEBUG_MODE) {
        Serial.print("[API] Response: ");
        Serial.println(response);
    }
    
    // CRITICAL: Must have BOTH success:true AND action:open
    if (response.indexOf("\"success\":true") > 0 && 
        response.indexOf("\"action\":\"open\"") > 0) {
        
        // Extract member name and duration if available
        int nameStart = response.indexOf("\"name\":\"");
        if (nameStart > 0) {
            nameStart += 8;
            int nameEnd = response.indexOf("\"", nameStart);
            String memberName = response.substring(nameStart, nameEnd);
            
            // Extract duration
            int durationStart = response.indexOf("\"duration\":\"");
            String durationText = "";
            if (durationStart > 0) {
                durationStart += 12;
                int durationEnd = response.indexOf("\"", durationStart);
                durationText = response.substring(durationStart, durationEnd);
                Serial.print("[EXIT GRANTED] Goodbye ");
                Serial.print(memberName);
                Serial.print(" - Duration: ");
                Serial.println(durationText);
            } else {
                Serial.print("[EXIT GRANTED] Goodbye: ");
                Serial.println(memberName);
            }
        } else {
            Serial.println("[EXIT GRANTED]");
        }
        
        return true;
    }
    
    // Extract denial reason
    int reasonStart = response.indexOf("\"message\":\"");
    if (reasonStart > 0) {
        reasonStart += 11;
        int reasonEnd = response.indexOf("\"", reasonStart);
        String reason = response.substring(reasonStart, reasonEnd);
        Serial.print("[EXIT DENIED] ");
        Serial.println(reason);
    } else {
        Serial.println("[EXIT DENIED] Unknown reason");
    }
    
    return false;
}

void openGate() {
    Serial.println("[GATE] Opening...");
    digitalWrite(RELAY_PIN, HIGH);
    delay(GATE_OPEN_DURATION_MS);
    digitalWrite(RELAY_PIN, LOW);
    Serial.println("[GATE] Closed");
}

String getRFIDString() {
    String uid = "";
    for (byte i = 0; i < rfid.uid.size; i++) {
        if (rfid.uid.uidByte[i] < 0x10) uid += "0";
        uid += String(rfid.uid.uidByte[i], HEX);
    }
    uid.toUpperCase();
    return uid;
}

String getTimestamp() {
    unsigned long seconds = millis() / 1000;
    unsigned long hours = seconds / 3600;
    unsigned long minutes = (seconds % 3600) / 60;
    unsigned long secs = seconds % 60;
    
    char timestamp[10];
    sprintf(timestamp, "%02lu:%02lu:%02lu", hours, minutes, secs);
    return String(timestamp);
}

// ============================================================================
// WIFI FUNCTIONS
// ============================================================================

void connectWiFi() {
    Serial.print("[WIFI] Connecting to ");
    Serial.print(WIFI_SSID);
    Serial.print("...");
    
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < MAX_WIFI_ATTEMPTS) {
        delay(WIFI_RECONNECT_DELAY_MS);
        Serial.print(".");
        attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
        Serial.println("\n[OK] WiFi connected");
        Serial.print("[INFO] IP: ");
        Serial.println(WiFi.localIP());
        Serial.print("[INFO] Signal: ");
        Serial.print(WiFi.RSSI());
        Serial.println(" dBm");
    } else {
        Serial.println("\n[ERROR] WiFi connection failed!");
        Serial.println("[ERROR] System cannot operate without WiFi");
        // Flash error indefinitely
        while (true) {
            blinkError();
            delay(1000);
        }
    }
}

void reconnectWiFi() {
    Serial.println("[WIFI] Attempting reconnection...");
    WiFi.disconnect();
    delay(1000);
    connectWiFi();
}

// ============================================================================
// HEALTH CHECK
// ============================================================================

bool checkServerHealth() {
    HTTPClient http;
    http.setTimeout(5000);  // 5 second timeout for health check
    
    String url = String(SERVER_URL).substring(0, String(SERVER_URL).lastIndexOf('/')) + "/health.php";
    
    Serial.print("[HEALTH] Checking server: ");
    Serial.println(url);
    
    http.begin(url);
    int httpCode = http.GET();
    
    if (httpCode == 200) {
        String response = http.getString();
        http.end();
        
        if (response.indexOf("\"status\":\"ok\"") > 0) {
            return true;
        }
    }
    
    http.end();
    return false;
}

// ============================================================================
// FEEDBACK FUNCTIONS
// ============================================================================

void blinkSuccess() {
    for (int i = 0; i < 2; i++) {
        digitalWrite(LED_PIN, HIGH);
        digitalWrite(BUZZER_PIN, HIGH);
        delay(100);
        digitalWrite(LED_PIN, LOW);
        digitalWrite(BUZZER_PIN, LOW);
        delay(100);
    }
}

void blinkError() {
    for (int i = 0; i < 3; i++) {
        digitalWrite(LED_PIN, HIGH);
        delay(200);
        digitalWrite(LED_PIN, LOW);
        delay(200);
    }
    digitalWrite(BUZZER_PIN, HIGH);
    delay(500);
    digitalWrite(BUZZER_PIN, LOW);
}

void blinkWarning() {
    for (int i = 0; i < 5; i++) {
        digitalWrite(LED_PIN, HIGH);
        delay(50);
        digitalWrite(LED_PIN, LOW);
        delay(50);
    }
}

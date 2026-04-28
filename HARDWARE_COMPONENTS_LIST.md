# Complete Hardware Components List - NFC Gate Automation System

## Required Components

### 1. ESP32 Development Board ⭐ (Main Controller)

**What it does:**
- Controls the entire gate system
- Reads NFC cards
- Controls relay modules
- Communicates with computer via USB

**Specifications:**
- **Model:** ESP32 DevKit V1 (or ESP32-WROOM-32)
- **Features needed:**
  - USB port (for programming and communication)
  - GPIO pins (for relays and SPI)
  - WiFi capability (optional, if using WiFi mode)
  - At least 2 free GPIO pins

**Recommended:**
- ESP32 DevKit V1 (most common)
- ESP32-WROOM-32 Development Board
- ESP32-S3 (newer, more powerful)

**Where to buy:**
- Amazon: Search "ESP32 DevKit"
- AliExpress: Search "ESP32 development board"
- Local electronics stores

**Price:** $5 - $15 USD

**Quantity needed:** 1 (or 2 if you want separate controllers for check-in and check-out gates)

---

### 2. PN532 NFC Reader Module ⭐ (NFC Card Reader) — **x2 for dual gates**

**What it does:**
- Reads NFC card UIDs
- Detects when members scan their cards
- Sends card data to ESP32

**Specifications:**
- **Model:** PN532 NFC Module
- **Interface:** SPI or I2C (SPI recommended for faster communication)
- **Reading distance:** 3-5 cm
- **Supported cards:** 
  - MIFARE Classic (most common)
  - NTAG (NTAG213, NTAG215, NTAG216)
  - ISO14443A cards

**Recommended:**
- PN532 NFC/RFID Controller Module
- V1.6 or newer version
- With SPI interface

**Where to buy:**
- Amazon: Search "PN532 NFC module"
- AliExpress: Search "PN532 SPI"
- eBay

**Price:** $3 - $8 USD

**Quantity needed:** 2 (one dedicated to Check-In, one dedicated to Check-Out)

---

### 3. Relay Modules (Gate Motor Control) ⭐

**What it does:**
- Controls gate motors (turns them on/off)
- Acts as a switch for high-voltage motors
- Isolates ESP32 from motor power

**Specifications:**
- **Type:** 5V Relay Module
- **Channels:** 2-channel (one for each gate)
- **Voltage:** 5V (matches ESP32)
- **Current:** 10A per channel (sufficient for most motors)
- **Isolation:** Optocoupler isolation (protects ESP32)

**Recommended:**
- 2-Channel 5V Relay Module
- With optocoupler isolation
- With status LEDs
- JQC-3FF relay type

**Where to buy:**
- Amazon: Search "5V relay module 2 channel"
- AliExpress: Search "5V relay module"
- Local electronics stores

**Price:** $2 - $5 USD per module

**Quantity needed:** 1 (2-channel module) OR 2 (single-channel modules)

---

### 4. Gate Motors (Physical Gates)

**What it does:**
- Opens and closes the physical gates
- Controlled by relay modules

**Options:**

#### Option A: Linear Actuator (Recommended for sliding gates)
- **Type:** 12V DC Linear Actuator
- **Stroke length:** 6-12 inches (15-30 cm)
- **Force:** 50-100 lbs (22-45 kg)
- **Speed:** 10-20 mm/s
- **Price:** $30 - $80 USD

#### Option B: Servo Motor (For rotating/swinging gates)
- **Type:** High-torque servo motor
- **Torque:** 20-30 kg/cm
- **Voltage:** 5V or 6V
- **Price:** $15 - $40 USD

#### Option C: Solenoid Lock (For simple lock/unlock)
- **Type:** 12V DC Solenoid Lock
- **Force:** 600-1200 lbs
- **Price:** $10 - $30 USD

#### Option D: Existing Gate Motor
- If you already have gate motors, just connect them to relays
- Make sure they're compatible with relay voltage

**Where to buy:**
- Amazon: Search "linear actuator 12V" or "servo motor high torque"
- AliExpress
- Local hardware stores

**Quantity needed:** 2 (one for check-in, one for check-out)

---

### 5. Power Supply

**What it does:**
- Powers ESP32, relays, and motors

**Specifications:**
- **For ESP32 + Relays:** 5V, 2A USB power supply (phone charger works!)
- **For Motors:** Depends on motor type:
  - 12V DC, 5-10A for linear actuators
  - 5V, 2A for servo motors
  - 12V, 2A for solenoid locks

**Recommended:**
- **Option 1:** Separate power supplies
  - 5V USB adapter for ESP32/relays
  - 12V adapter for motors (if using 12V motors)
- **Option 2:** Single 12V power supply with voltage regulator
  - 12V, 10A power supply
  - 5V voltage regulator for ESP32/relays

**Where to buy:**
- Amazon: Search "12V 10A power supply" or "5V 2A USB adapter"
- Electronics stores

**Price:** $5 - $20 USD

**Quantity needed:** 1-2 (depending on setup)

---

### 6. Jumper Wires (Connections)

**What it does:**
- Connects all components together

**Specifications:**
- **Type:** Male-to-Female jumper wires
- **Length:** 20cm (8 inches) or 30cm (12 inches)
- **Gauge:** 22 AWG or 24 AWG

**Recommended:**
- Dupont jumper wires
- Male-to-Female (for breadboard connections)
- Male-to-Male (for direct connections)
- Various colors (for easy identification)

**Where to buy:**
- Amazon: Search "jumper wires male female"
- AliExpress
- Electronics stores

**Price:** $2 - $5 USD for 40-piece set

**Quantity needed:** 1 set (40 wires)

---

### 7. Breadboard (Optional but Recommended)

**What it does:**
- Makes connections easier
- Allows testing before permanent installation
- No soldering needed

**Specifications:**
- **Size:** Half-size or full-size breadboard
- **Holes:** 400+ tie points
- **Type:** Solderless breadboard

**Where to buy:**
- Amazon: Search "breadboard"
- Electronics stores

**Price:** $3 - $8 USD

**Quantity needed:** 1 (optional, for testing)

---

### 8. USB Cable (ESP32 Connection)

**What it does:**
- Connects ESP32 to computer
- For programming and communication

**Specifications:**
- **Type:** USB-A to Micro-USB (for most ESP32)
- **OR:** USB-A to USB-C (for newer ESP32)
- **Length:** 1-2 meters (3-6 feet)
- **Quality:** Data cable (not just charging cable)

**Where to buy:**
- Amazon: Search "micro USB cable data"
- Electronics stores
- Usually comes with ESP32

**Price:** $2 - $5 USD

**Quantity needed:** 1

---

### 9. NFC Cards/Tags (For Members)

**What it does:**
- Members use these to scan at gates
- Each card has unique UID

**Specifications:**
- **Type:** MIFARE Classic or NTAG
- **Size:** Credit card size or keychain tag
- **Memory:** 1KB (MIFARE) or 144 bytes (NTAG213)
- **UID:** Unique identifier (what system reads)

**Recommended:**
- **MIFARE Classic 1K** - Most common, reliable
- **NTAG213** - Smaller, cheaper, good for keychains
- **NTAG215** - More memory, good for cards

**Where to buy:**
- Amazon: Search "MIFARE cards" or "NFC tags"
- AliExpress: Search "MIFARE 1K cards"
- Bulk purchases are cheaper

**Price:** $0.50 - $2 USD per card (cheaper in bulk)

**Quantity needed:** 1 per member (buy extras for replacements)

---

### 10. Enclosure Box (Optional but Recommended)

**What it does:**
- Protects ESP32 and relays from dust/water
- Makes installation look professional
- Prevents accidental damage

**Specifications:**
- **Material:** Plastic or metal
- **Size:** Large enough for ESP32 + relays + wiring
- **Features:** 
  - Ventilation holes
  - Mounting holes
  - Cable entry points

**Where to buy:**
- Amazon: Search "project enclosure box"
- Electronics stores

**Price:** $5 - $15 USD

**Quantity needed:** 1-2 (one for each gate location)

---

## Complete Shopping List

### Essential Components (Minimum Setup):

| Component | Quantity | Estimated Price |
|-----------|----------|----------------|
| ESP32 DevKit | 1 | $10 |
| PN532 NFC Module | 1 | $5 |
| 2-Channel Relay Module | 1 | $3 |
| Gate Motors (2x) | 2 | $60 |
| Power Supply (12V, 10A) | 1 | $15 |
| Jumper Wires (40pc) | 1 set | $3 |
| USB Cable (Micro-USB) | 1 | $3 |
| NFC Cards (50 cards) | 50 | $25 |
| **TOTAL** | | **~$124 USD** |

### Optional Components:

| Component | Quantity | Estimated Price |
|-----------|----------|----------------|
| Breadboard | 1 | $5 |
| Enclosure Box | 2 | $20 |
| Voltage Regulator (if needed) | 1 | $2 |
| Extra Jumper Wires | 1 set | $3 |
| **TOTAL OPTIONAL** | | **~$30 USD** |

### Grand Total: ~$150 USD (with optional items)

---

## Component Specifications Summary

### ESP32 Requirements:
- ✅ USB port for programming
- ✅ At least 2 GPIO pins (for relays)
- ✅ SPI pins (for PN532)
- ✅ 5V power input

### PN532 Requirements:
- ✅ SPI interface (recommended)
- ✅ 3.3V power (ESP32 provides)
- ✅ 4 wires: VCC, GND, MOSI, SCK, SS

### Relay Requirements:
- ✅ 5V input (matches ESP32)
- ✅ 2 channels (one per gate)
- ✅ 10A per channel (for motors)
- ✅ Optocoupler isolation

### Motor Requirements:
- ✅ Compatible with relay voltage (12V DC recommended)
- ✅ Sufficient force for your gate type
- ✅ Appropriate speed (not too fast)

---

## Where to Buy (Recommended Sources)

### Online (International):
1. **Amazon** - Fast shipping, good quality
2. **AliExpress** - Cheaper, longer shipping
3. **eBay** - Good for used/refurbished
4. **Banggood** - Good prices, reliable

### Local:
1. **Electronics stores** (RadioShack, Fry's, etc.)
2. **Hardware stores** (for motors, enclosures)
3. **Maker spaces** (for advice and components)

### Specialized:
1. **Adafruit** - High quality, good documentation
2. **SparkFun** - Reliable, good support
3. **Seeed Studio** - Good prices, quality products

---

## Wiring Diagram Components

### ESP32 Pin Connections:

```
ESP32 Pin    →    Component
─────────────────────────────
GPIO 23      →    PN532 MOSI (SPI)
GPIO 18      →    PN532 SCK (SPI)
GPIO 5       →    PN532 SS (CS)
GPIO 2       →    Relay 1 (Check-In Gate)
GPIO 4       →    Relay 2 (Check-Out Gate)
5V           →    Relay VCC
GND          →    Relay GND, PN532 GND
3.3V         →    PN532 VCC
USB          →    Computer (for communication)
```

---

## Alternative: All-in-One Solutions

If you want something simpler, consider:

### Option 1: ESP32 NFC Development Board
- ESP32 + PN532 already integrated
- Price: $15-25 USD
- Easier setup, less wiring

### Option 2: Pre-made Relay Board
- Multiple relays on one board
- Price: $5-10 USD
- Cleaner installation

### Option 3: Motor Driver Board
- H-bridge motor driver
- Better control than simple relay
- Price: $3-8 USD
- Allows forward/reverse control

---

## Budget Options

### Minimum Budget Setup (~$100):
- ESP32: $10
- PN532: $5
- 2-Channel Relay: $3
- 2x Simple Motors: $40
- Power Supply: $10
- Wires: $3
- 20 NFC Cards: $10
- USB Cable: $3
- **Total: ~$84 USD**

### Professional Setup (~$200):
- ESP32: $15 (better model)
- PN532: $8 (better quality)
- 2-Channel Relay: $5 (higher current)
- 2x Quality Motors: $100
- Power Supply: $20 (regulated)
- Enclosure: $20
- 50 NFC Cards: $25
- Extra components: $7
- **Total: ~$200 USD**

---

## Important Notes

### ⚠️ Safety Considerations:
1. **Use proper fuses** for motor circuits
2. **Isolate high voltage** (motors) from low voltage (ESP32)
3. **Use proper wire gauge** for motor current
4. **Install properly** to prevent accidents
5. **Test thoroughly** before production use

### ✅ Compatibility:
- All components are standard and widely available
- ESP32 works with most relay modules
- PN532 is industry standard
- Motors can be any 12V DC type

### 📦 Buying Tips:
- Buy from reputable sellers
- Check reviews before purchasing
- Buy extra components (for backups)
- Consider buying kits (often cheaper)
- NFC cards are cheaper in bulk (50+ cards)

---

## Next Steps After Buying

1. **Test each component** individually
2. **Follow wiring diagrams** carefully
3. **Upload ESP32 code** (esp32_nfc_gate_usb.ino)
4. **Test with one card** first
5. **Install at gym location**
6. **Assign NFC cards to members**

---

**This list covers everything you need for a complete NFC gate automation system!** ✓


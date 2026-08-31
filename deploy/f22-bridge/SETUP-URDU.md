# F22 Lock Setup — Gym Owner ke liye (Roman Urdu)

Yeh guide reception laptop pe chalane ke liye hai. Isse aap ke gym ka F22
fingerprint device aise ho jayega ke **jis member ne fee nahi di, us ka thumb
gate nahi kholega** — aur jab woh fee de dega, uska thumb dobara chal jayega
(dobara finger lagane ki zaroorat nahi).

---

## Zaroori baatein pehle

- Yeh sirf **reception ke laptop** pe chalega jo gym khulne ke doran ON rehta hai.
- Laptop aur F22 device **same WiFi** pe hone chahiye.
- Pehla test **bilkul safe hai — kuch delete nahi hota, sirf check karta hai.**

---

## Step 1 — Python install karein (ek dafa, 3 minute)

1. Browser mein jayein: **python.org/downloads**
2. Python download kar ke installer chalayein.
3. **Bohot zaroori:** neeche jo box likha hai **"Add Python to PATH"** — us pe
   tick zaroor lagayein. Phir "Install Now" dabayein.

---

## Step 2 — Bridge folder laptop pe copy karein

Jo `f22-bridge` folder maine bheja hai, usse laptop pe copy karein — yahan:
```
C:\f22-bridge\
```
(USB, email ya WhatsApp se bhej ke.)

Phir Command Prompt kholein:
- Keyboard pe `Windows` button dabayein
- `cmd` type karein, Enter dabayein
- Yeh command likhein aur Enter:
```
pip install pyzk requests
```

---

## Step 3 — CONNECTION TEST (sab se pehle yeh karein)

**Yeh 100% safe hai — koi fingerprint delete nahi hoga. Sirf check hai.**

Command Prompt mein:
```
cd C:\f22-bridge
python test_connection.py 192.168.18.16
```

**Result parhein:**

- ✅ Agar likha aaye **`[SUCCESS] The bridge CAN control this device`**
  → Bohot achha! System chal sakta hai. Screenshot bhej dein, main aage bata deta hun.

- ❌ Agar likha aaye **`[FAIL] Could not reach the F22`**
  → Laptop device se baat nahi kar pa raha. Check karein:
    - Laptop aur F22 **same WiFi** pe hain?
    - F22 ka IP `192.168.18.16` hi hai? (Device pe: Menu → Comm → Ethernet dekhein)
  → Phir bhi na chale to mujhe screenshot bhej dein.

**Har haal mein screenshot bhej dein — main dekh ke agla step bata dunga.**

---

## Step 4 — Baaki setup

Jab Step 3 SUCCESS aa jaye, main aap ko baaki 2 step (config + auto-start)
guide kar dunga — woh 5 minute ka kaam hai. Ek dafa set ho jaye to laptop on
karne pe khud chalu ho jayega, har roz kholne ki zaroorat nahi.

---

## System kaise kaam karega (samajhne ke liye)

- **Fee nahi di** → member ka fingerprint device se hat jayega → thumb lagane pe
  gate nahi khulega.
- **Fee de di** (aap dashboard pe renew karenge) → 45 second mein uska fingerprint
  wapas aa jayega → thumb dobara chal jayega. **Dobara finger lagane ki zaroorat nahi.**
- **Jin logon ne fee di hai** → un ko kuch nahi hoga, normal chalte rahenge.
- **Laptop band ho jaye** → F22 phir bhi normal chalta rahega (jo log abhi device
  mein hain un ke liye gate khulta rahega). Sirf blocking ruk jayegi jab tak laptop
  wapas on na ho — koi cheez kharab nahi hoti.

---

## Ek zaroori baat

Laptop pe `C:\f22-bridge\cache\` folder banega — **isse delete na karein.** Yeh
woh jagah hai jahan members ke fingerprint mehfooz rehte hain, taake fee dene par
un ka thumb wapas chal jaye. Ise safe rakhein.

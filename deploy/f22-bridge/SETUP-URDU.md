# F22 Lock Setup — Gym Owner ke liye (Roman Urdu)

Yeh reception laptop pe chalega. Isse jis member ne fee nahi di, us ka thumb
gate nahi kholega — aur fee dene par khud wapas chal jayega (dobara finger
lagane ki zaroorat nahi).

**Zaroori:** laptop aur F22 device **same WiFi** pe hone chahiye.

---

## Step 1 — Python install karein (ek dafa)

1. Browser mein jayein: **python.org/downloads**
2. Installer download kar ke chalayein.
3. **Sab se zaroori:** neeche box **"Add Python to PATH"** pe tick lagayein,
   phir "Install Now".

---

## Step 2 — Bridge folder laptop pe copy karein

`f22-bridge` folder ko laptop pe rakhein, yahan: `C:\f22-bridge\`

Us folder mein aap ko yeh files dikhengi — inhe number ke hisaab se chalana hai:
- `1-install.bat`
- `2-test-connection.bat`
- `3-run-bridge.bat`

---

## Step 3 — `1-install.bat` pe DOUBLE-CLICK karein

Ek dafa ka kaam hai. Yeh zaroori cheezein install karega. Jab "Done" likha aaye,
window band kar dein.

(Agar error aaye ke Python nahi mila — matlab Step 1 theek se nahi hua. Python
dobara install karein, "Add to PATH" tick zaroor karein.)

---

## Step 4 — `2-test-connection.bat` pe DOUBLE-CLICK karein

**Yeh 100% safe hai — kuch delete nahi hota, sirf check karta hai.**

- ✅ Agar **`[SUCCESS]`** likha aaye → bohot achha, system chal sakta hai.
- ❌ Agar **`[FAIL]`** aaye → laptop device se baat nahi kar pa raha (shayad
  alag WiFi pe hai, ya device ka IP badal gaya).

**Screenshot le kar Zeeshan ko bhej dein — chahe SUCCESS ho ya FAIL.**

---

## Step 5 — Baaki setup

Jab test SUCCESS aa jaye, `3-run-bridge.bat` chalana hai. Yeh **pehle sirf
"monitor" mode** mein chalega — matlab yeh sirf batayega ke kaun kaun se member
block honge, lekin **abhi kisi ka fingerprint nahi hatayega.** Screenshot bhej
dein, Zeeshan check karega ke sahi log block ho rahe hain. **Uski confirmation ke
baad** hi asal blocking chalu hogi.

Ek dafa set ho jaye to laptop on karne pe khud chalu ho jayega.

---

## System kaise kaam karega

- **Fee nahi di** → member ka fingerprint device se hat jayega → thumb lagane pe
  gate nahi khulega.
- **Fee de di** (aap dashboard pe renew karenge) → 45 second mein fingerprint
  wapas → thumb dobara chalega. **Dobara finger lagane ki zaroorat nahi.**
- **Jinhone fee di hai** → un ko kuch nahi hoga.
- **Laptop band** → F22 normal chalta rahega, sirf blocking ruk jayegi. Kuch
  kharab nahi hota.

---

## Do zaroori baatein

1. `C:\f22-bridge\cache\` folder ko **delete na karein** — yahan members ke
   fingerprint mehfooz rehte hain (fee dene par wapas lagane ke liye).

2. Setup se pehle Zeeshan ek sawal poochega: **"Jin members ki due date nikal
   gayi, kya unhone cash mein fee di jo system mein update nahi hui?"** — kyunke
   agar aisa hai to woh log ghalti se block ho sakte hain. Pehle confirm kar lein.

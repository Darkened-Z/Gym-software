# Oracle Free VPS — One-Time Setup

Goal: get a free-forever VPS that hosts many gym installs at subdomains of your domain (e.g. `ironhouse.yourdomain.com`, `fitzone.yourdomain.com`).

Once this is done, every new gym takes one command: `sudo provision-gym.sh <slug>`.

---

## 0. What you need before starting

- A domain you own (any registrar — Namecheap, GoDaddy, Cloudflare, etc.)
- An Oracle Cloud account (free, but they may ask for a card for verification — won't be charged)
- 30–45 minutes for the one-time setup
- An email address for Let's Encrypt notifications

---

## 1. Create the VM

1. Sign up / log in at [cloud.oracle.com](https://cloud.oracle.com).
2. Top-left menu → **Compute → Instances → Create instance**.
3. **Name:** `gym-saas`
4. **Image:** Ubuntu 22.04 (or 24.04)
5. **Shape:** click **Change shape** → pick **Ampere → VM.Standard.A1.Flex**. Set OCPUs = **2**, memory = **12 GB**. (You're allowed up to 4 OCPUs / 24 GB on Free Tier.)
6. **Networking:** keep "Assign a public IPv4 address" enabled.
7. **SSH keys:** generate a key pair — download the private key (`.key` file). Keep it safe.
8. Click **Create**.

When the instance is **Running**, note its **Public IPv4 address**.

> If Oracle says "out of capacity" for ARM (common): retry every few hours, or pick the **AMD x86 e2-micro** shape (smaller — 1 vCPU / 1 GB — but always available).

---

## 2. Open the firewall (Oracle side)

The VM has a virtual firewall on top of the OS firewall. By default only SSH (22) is open.

1. Open your VM's **Subnet** → click **Security List → Default Security List for vcn-…**
2. **Add Ingress Rules** — two rules:
   - Source CIDR `0.0.0.0/0`, IP Protocol **TCP**, Destination Port **80**
   - Source CIDR `0.0.0.0/0`, IP Protocol **TCP**, Destination Port **443**
3. Save.

---

## 3. Point DNS at the VM

At your domain registrar, add a **wildcard A record**:

| Host | Type | Value | TTL |
|---|---|---|---|
| `*` | A | `<your VM public IP>` | 300 |

This way *any* subdomain (`anything.yourdomain.com`) resolves to your VM. You won't need to touch DNS again per gym.

Verify (from your laptop):
```
nslookup test123.yourdomain.com
```
Should return your VM's IP within a few minutes.

---

## 4. SSH in and install the stack

```bash
chmod 600 /path/to/your-key.key
ssh -i /path/to/your-key.key ubuntu@<vm-public-ip>
```

Update + install everything:

```bash
sudo apt update && sudo apt upgrade -y

sudo apt install -y \
    nginx \
    mysql-server \
    php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring \
    php8.2-zip php8.2-gd php8.2-curl php8.2-xml php8.2-bcmath \
    composer git certbot python3-certbot-nginx \
    nodejs npm \
    ufw
```

> If `php8.2` isn't available on your Ubuntu version, swap for `php8.1` and adjust `PHP_FPM_SOCK` later.

---

## 5. Secure MySQL

```bash
sudo mysql_secure_installation
```

Answer:
- Set root password? **Yes** — set a strong one
- Remove anonymous users? **Yes**
- Disallow remote root login? **Yes**
- Remove test database? **Yes**
- Reload privilege tables? **Yes**

The provision script will create per-gym MySQL users automatically — the root password is just for you.

---

## 6. Set up the OS firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

Type `y` when prompted.

---

## 7. Install the gym-deploy scripts

```bash
# Pull the repo somewhere safe
git clone https://github.com/Darkened-Z/Gym-software.git /opt/gym-deploy-src

# Install scripts to /usr/local/bin
sudo install -m 0755 /opt/gym-deploy-src/deploy/provision-gym.sh    /usr/local/bin/
sudo install -m 0755 /opt/gym-deploy-src/deploy/deprovision-gym.sh  /usr/local/bin/
sudo install -m 0755 /opt/gym-deploy-src/deploy/update-all-gyms.sh  /usr/local/bin/
sudo install -m 0755 /opt/gym-deploy-src/deploy/list-gyms.sh        /usr/local/bin/
sudo install -m 0755 /opt/gym-deploy-src/deploy/backup-all-gyms.sh  /usr/local/bin/

# Install nginx template
sudo mkdir -p /etc/gym-deploy
sudo cp /opt/gym-deploy-src/deploy/nginx-vhost.conf.template /etc/gym-deploy/

# Install whatsapp-bot systemd unit (yourdomain.com gets replaced with your real base domain)
sudo sed "s/yourdomain\.com/${BASE_DOMAIN:-yourdomain.com}/g" \
    /opt/gym-deploy-src/deploy/gym-whatsapp@.service \
    | sudo tee /etc/systemd/system/gym-whatsapp@.service > /dev/null
sudo systemctl daemon-reload

# Configure
sudo tee /etc/gym-deploy.conf > /dev/null <<EOF
BASE_DOMAIN=yourdomain.com
REPO_URL=https://github.com/Darkened-Z/Gym-software.git
LETSENCRYPT_EMAIL=you@yourdomain.com
PHP_FPM_SOCK=/var/run/php/php8.2-fpm.sock
DEFAULT_TIMEZONE=Asia/Karachi
EOF
sudo chmod 600 /etc/gym-deploy.conf
```

Edit `/etc/gym-deploy.conf` to match your actual domain + email.

---

## 8. Provision your first gym

```bash
sudo provision-gym.sh ironhouse
```

The script will:
1. Clone the repo to `/var/www/ironhouse.yourdomain.com`
2. Create a MySQL database `gym_ironhouse` with a unique user
3. Import the schema
4. Write `.env` with the right DB credentials
5. Run `composer install`
6. Drop an nginx vhost + reload
7. Run certbot to get a Let's Encrypt SSL cert
8. Save credentials to `/etc/gym-deploy/credentials/ironhouse.yourdomain.com.txt`

After ~2 minutes you'll see:
```
✓ Provisioned: https://ironhouse.yourdomain.com
NEXT: visit https://ironhouse.yourdomain.com/setup.php to activate
```

Visit that URL, run setup, log in as `admin` / `admin123`, change the password — done.

---

## 9. Adding more gyms

Just run `sudo provision-gym.sh <slug>` again per gym. No DNS work, no nginx editing, no MySQL setup. Each gym is fully isolated:

```
/var/www/ironhouse.yourdomain.com/   ← own files, own .env
/var/www/fitzone.yourdomain.com/     ← own files, own .env
/var/www/powerhouse.yourdomain.com/  ← own files, own .env
```

```
MySQL: gym_ironhouse, gym_fitzone, gym_powerhouse  ← separate DBs
```

---

## 10. Per-gym customization (logo, colors, default rates)

Since the app doesn't have a built-in branding UI, customization happens per install. After provisioning a new gym:

```bash
# Logo
sudo cp /path/to/their-logo.png /var/www/<slug>.yourdomain.com/assets/images/logo.png

# Brand color — edit the CSS root variable
sudo nano /var/www/<slug>.yourdomain.com/assets/css/style.css

# Default fees, gym name etc — change once via the admin dashboard
# (Settings → General)
```

You only touch this once per new gym at signup time.

---

## 11. Updating all gyms when you ship a fix

After pushing a code update to GitHub:

```bash
sudo update-all-gyms.sh
```

This pulls latest into every `/var/www/*.yourdomain.com/` and reruns `composer install`. Each install stays on the branch it's on (`main` by default).

---

## 12. Removing a gym (cancellation / churn)

```bash
sudo deprovision-gym.sh ironhouse
```

This will:
- Back up the database to `/var/backups/gym-deploy/gym_ironhouse-<timestamp>.sql.gz`
- Back up the files to `/var/backups/gym-deploy/ironhouse.yourdomain.com-files-<timestamp>.tar.gz`
- Drop the database + user
- Remove the nginx vhost
- Revoke the SSL cert
- Delete the install folder

Backups are kept so you can restore later if they come back.

---

## Cost: $0 / month

The Always Free tier covers:
- 1 ARM A1 instance up to **4 cores / 24 GB RAM** (or 2 x86 e2-micro)
- 200 GB block storage
- 10 TB egress per month

You won't be charged unless you exceed these limits. Realistic capacity: **30–50 active gyms** before the VM starts complaining.

---

## Daily backups (set this up before going live)

Schedule `backup-all-gyms.sh` to run nightly:

```bash
sudo crontab -e
# add:
15 3 * * * /usr/local/bin/backup-all-gyms.sh >> /var/log/gym-backup.log 2>&1
```

This dumps every `gym_*` database to `/var/backups/gym-deploy/daily/` at 3:15am and prunes anything older than 7 days. Adjust `BACKUP_RETENTION_DAYS` in `/etc/gym-deploy.conf` if you want longer.

For real safety, also sync `/var/backups/gym-deploy/` to off-server storage (Oracle Object Storage is free up to 20 GB).

---

## Per-gym WhatsApp bot (optional)

Each gym has its own `whatsapp-bot/` directory. To run one as a background service that survives reboots:

```bash
sudo systemctl enable --now gym-whatsapp@ironhouse
sudo journalctl -u gym-whatsapp@ironhouse -f    # first run: scan the QR shown here
```

To stop a gym's bot:
```bash
sudo systemctl disable --now gym-whatsapp@ironhouse
```

---

## Operational tools

| Command | What |
|---|---|
| `sudo list-gyms.sh` | Show every install: URL, install date, DB size, SSL status |
| `sudo backup-all-gyms.sh` | Force a backup now (also runs nightly via cron) |
| `sudo update-all-gyms.sh` | Git pull latest into every install |
| `sudo provision-gym.sh <slug>` | Spin up a new gym |
| `sudo deprovision-gym.sh <slug>` | Remove a gym (with backup) |

---

## Things that will eventually need attention

| When | What |
|---|---|
| Every 60 days | SSL certs auto-renew via certbot timer (already set up). Check: `sudo systemctl list-timers \| grep certbot` |
| Monthly | Run `sudo apt update && sudo apt upgrade -y` |
| When you have 20+ gyms | Tune PHP-FPM `pm.max_children` in `/etc/php/8.2/fpm/pool.d/www.conf` |
| When traffic spikes | Move MySQL to a separate Oracle instance (still free up to 2 instances) |

# Multi-Install Deployment Toolkit

Scripts + docs to run **many separate gym installs** on **one Oracle Free VPS**, each at its own subdomain with its own database. The app code is unchanged — each gym just gets its own isolated install.

```
/var/www/gym1.yourdomain.com/   ← own files, own DB (gym_gym1)
/var/www/gym2.yourdomain.com/   ← own files, own DB (gym_gym2)
/var/www/gym3.yourdomain.com/   ← own files, own DB (gym_gym3)
```

## Files

| File | What it does |
|---|---|
| [VPS_SETUP.md](VPS_SETUP.md) | **Read this first.** One-time Oracle VPS provisioning walkthrough. |
| [provision-gym.sh](provision-gym.sh) | `sudo provision-gym.sh <slug>` — creates a new gym install in ~2 min. |
| [deprovision-gym.sh](deprovision-gym.sh) | `sudo deprovision-gym.sh <slug>` — removes a gym (with backup). |
| [update-all-gyms.sh](update-all-gyms.sh) | `sudo update-all-gyms.sh` — git pulls latest code into every install. |
| [nginx-vhost.conf.template](nginx-vhost.conf.template) | Per-install nginx config (used by provision-gym.sh). |

## TL;DR

```bash
# One time, on a fresh Oracle Free Ubuntu VM:
# (see VPS_SETUP.md for the full walkthrough)

# Per new gym:
sudo provision-gym.sh ironhouse
# → https://ironhouse.yourdomain.com is live in ~2 minutes
# → visit /setup.php once to activate

# When you ship a code update:
git push origin main          # (on your laptop)
sudo update-all-gyms.sh       # (on the VPS)

# When a customer leaves:
sudo deprovision-gym.sh ironhouse
# → backups saved to /var/backups/gym-deploy/
```

## What this is NOT

- **Not** a multi-tenant SaaS rewrite. The app code is unchanged. Each install is fully independent.
- **Not** automatic per-gym branding. Each install's logo/CSS is edited once at provision time. (See VPS_SETUP §10.)
- **Not** a self-serve signup. You run `provision-gym.sh` per gym; gyms can't sign themselves up.

If you ever want true multi-tenancy (one URL, gyms log in, see only their data with their own branding self-served), that's a much bigger code rewrite — `RAILWAY_DEPLOY.md`-style configs don't get you there. This toolkit is the pragmatic middle: real isolation, near-zero cost, no app changes.

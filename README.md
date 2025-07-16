# DNS Monitor

Keep a vigilant eye on your domain's most critical infrastructure. DNS Monitor automatically tracks your DNS records, takes periodic snapshots, and instantly alerts you to any changes. Prevent downtime, detect unauthorized modifications, and gain peace of mind knowing your site's foundation is secure.

---

## Description

Your website's DNS records are the foundation of its online presence. They direct users to your server, handle your email, and verify your domain's identity. An unauthorized or incorrect change can lead to catastrophic downtime, email delivery failures, or even security breaches.

DNS Monitor acts as your automated security guard, providing a simple yet powerful way to monitor these critical records directly from your WordPress dashboard.

### Why Use DNS Monitor?

-   **Prevent Costly Downtime:** Your 'A' record is the most critical part of your DNS configuration, pointing users to your web server. Get alerted the moment it changes, allowing you to react before your users are affected.
-   **Detect Malicious Activity:** Instantly know if a hacker attempts to hijack your domain by modifying its 'A' record.
-   **Maintain a Historical Record:** Keep a complete history of your 'A' record with timestamped snapshots. Easily see what changed and when.
-   **Focused Monitoring:** By concentrating only on the 'A' record, the plugin provides a lightweight and highly targeted solution for ensuring your site's primary accessibility.

### Key Features

-   **Automatic 'A' Record Snapshots:** The plugin runs on a schedule using WP-Cron to automatically fetch and store your domain's 'A' record.
-   **Instant Change Alerts:** Receive an email notification the moment any change, addition, or deletion is detected between snapshots.
-   **Modern, Reactive Dashboard:** Built with a fast, responsive interface that updates without full page reloads.
-   **Clear Comparison View:** Easily visualize what's changed. Modified records are highlighted, showing both the old and new values side-by-side.

---

## Installation

1.  Navigate to `Plugins` > `Add New` from your WordPress dashboard.
2.  Search for "DNS Monitor".
3.  Click `Install Now` and then `Activate`.
4.  Navigate to the "DNS Monitor" menu in your admin sidebar to view the dashboard and its initial snapshot.

---

## Frequently Asked Questions

**What DNS records does the plugin check?**
DNS Monitor is specifically designed to check your domain's 'A' record, which maps your domain name to its IP address.

**How often does the plugin check for changes?**
The plugin uses the default WordPress Cron schedule. A check is initiated once every hour.

**Which DNS server is used for the lookup?**
The plugin uses the default DNS resolver configured for your web server's operating system. This ensures it sees your DNS records from the same perspective as most web traffic.

**Can I change the email address for notifications?**
By default, notifications are sent to the site administrator's email address, which can be configured under `Settings` > `General` in your WordPress dashboard.

---

## Screenshots

1.  **Main Dashboard:** A clean overview of all historical snapshots, showing the most recent at the top.
2.  **Record Comparison:** A detailed view showing added, removed, and modified records between snapshots.
3.  **On-Demand DNS Check:** The fast, HTMX-powered interface allows for instant DNS checks without reloading the page.

---

## Changelog

### 1.0.1

-   Tweak: Improved plugin description and readme file for clarity.
-   Fix: Corrected version mismatch between plugin header and defined constant.

### 1.0.0

-   Initial public release.

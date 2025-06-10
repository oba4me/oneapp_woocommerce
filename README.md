# OneApp WooCommerce Payment Gateway

**OneApp WooCommerce Plugin** – Seamlessly integrates the 1App (Boldd) payment gateway into your WooCommerce store.

## 🚀 Features

- Adds **1App / Boldd** as a payment method in WooCommerce checkout.
- Securely handles payment tokenization and processing.
- Supports sandbox and live modes for testing and production.
- Easy configuration via WooCommerce settings panel.
- Logs debug information to assist during setup.

## 🔧 Requirements

- **PHP 7.4+**
- **WooCommerce** 4.x or newer
- A **1App / Boldd API account** (credentials required)

## 🧩 Installation

1. Clone or download this repository.
2. Copy the plugin folder to your WooCommerce plugins directory:
3. Activate the plugin via the **WordPress Admin → Plugins** screen.
4. Navigate to **WooCommerce → Settings → Payments → 1App / Boldd** to configure API credentials and settings.

## ⚙️ Configuration

| Setting            | Description                                      |
|--------------------|--------------------------------------------------|
| **API Key / Secret** | Obtained from your 1App / Boldd merchant account |
| **Sandbox Mode**      | Toggle to enable testing without live payments |
| **Logging**           | Enable debug logs for troubleshooting         |

## 💳 Usage

1. When customers reach checkout, they can choose **1App / Boldd** as the payment method.
2. On form submission, the plugin securely sends payment data to the gateway.
3. Orders are updated based on the payment response (e.g., `processing`, `completed`, or `failed`).

## 🧪 Testing

- Enable **Sandbox Mode** and run test transactions.
- Use sample card details provided by 1App for sandbox testing.
- Confirm order status and check debug log file for request/response details.

## 🛠️ Troubleshooting

- Check WooCommerce status and logs under **WooCommerce → Status → Logs**.
- Enable plugin **debug logging** for detailed gateway communication logs.
- Contact 1App support for API-specific issues.

## 📦 Developer Notes

- Main entry: `oneapp-custom-checkout.php`
- Core functions:
- `init_payment_gateway()`
- `process_payment()`
- `handle_response()`
- Contributions via pull requests are welcome—please adhere to WordPress PHP standards.

## 📃 License

Distributed under the **GPL‑2.0** license. See `LICENSE` for full details.

## 🧩 Contributing & Support

- Found a bug or want a new feature? Open an **Issue**.
- Pull requests are reviewed and merged if aligned with project direction.
- For help with integration, contact **oba4me@outlook.com** (replace with real contact).

---

*Created and maintained by OBA4ME*  
*Plugin version: 1.0.0*

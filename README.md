<div align="center">
  <h1 align="center">Juleb WooCommerce Sync</h1>
  <p align="center">
    A powerful WordPress plugin to seamlessly synchronize your WooCommerce store with the Juleb API.
    <br />
    <a href="#"><strong>Explore the docs »</strong></a>
    <br />
    <br />
    <a href="#">View Demo</a>
    ·
    <a href="#">Report Bug</a>
    ·
    <a href="#">Request Feature</a>
  </p>
</div>

<!-- SHIELDS -->
<div align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="License"></a>
  <a href="https://www.wordpress.org"><img src="https://img.shields.io/badge/WordPress-^5.0-blue.svg" alt="WordPress"></a>
  <a href="https://www.woocommerce.com"><img src="https://img.shields.io/badge/WooCommerce-^4.0-lightgrey.svg" alt="WooCommerce"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-^7.4-8892BF.svg" alt="PHP"></a>
</div>

---

## 📖 About The Project

**Juleb WooCommerce Sync** is a WordPress plugin designed to create a robust bridge between your WooCommerce-powered online store and the Juleb ERP system. It automates data synchronization, ensuring that your products, orders, and customer information are consistent across both platforms.

This eliminates manual data entry, reduces errors, and provides a real-time overview of your business operations.

---

## ✨ Key Features

- ✅ **Product Sync:** Keep product details, stock levels, and pricing synchronized between WooCommerce and Juleb.
- ✅ **Order Sync:** Automatically push new orders from WooCommerce to the Juleb system for processing.
- ✅ **Customer Sync:** Sync customer data upon registration or when new orders are placed.
- ✅ **QR Code Generation:** Generate QR codes for orders, products, or other custom data points.
- ✅ **Intuitive Admin Panel:** An easy-to-use settings page within the WordPress dashboard to manage API credentials and plugin configurations.

---

## 🚀 Getting Started

Follow these steps to get the plugin up and running on your WooCommerce store.

### Prerequisites

Ensure your server environment meets the following requirements:

| Requirement  | Version      |
| :----------- | :----------- |
| WordPress    | `5.0` or higher |
| WooCommerce  | `4.0` or higher |
| PHP          | `7.4` or higher |
| Composer     | `2.0` or higher |

### Installation

1.  **Download the Plugin**
    -   Clone the repository or download the latest release as a `.zip` file.

2.  **Install via WordPress Admin**
    -   Navigate to `Plugins > Add New` in your WordPress dashboard.
    -   Click `Upload Plugin` and select the downloaded `.zip` file.
    -   Click `Install Now` and then `Activate`.

3.  **Install Dependencies**
    -   Connect to your server via SSH and navigate to the plugin's directory:
        ```sh
        cd wp-content/plugins/juleb-woocommerce-sync
        ```
    -   Run Composer to install the required vendor packages:
        ```sh
        composer install --no-dev
        ```

---

## 🔧 Configuration

After activating the plugin, you need to configure it to connect to your Juleb account.

1.  Navigate to `Juleb Sync` (or a similar menu item) in your WordPress dashboard sidebar.
2.  Enter your **Juleb API Key** and **API Endpoint URL** in the respective fields.
3.  Configure any other synchronization settings as needed (e.g., sync frequency, data mapping).
4.  Click `Save Changes`. The plugin is now ready to sync data.

---

## 🤝 Contributing

Contributions are what make the open-source community such an amazing place to learn, inspire, and create. Any contributions you make are **greatly appreciated**.

1.  Fork the Project
2.  Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3.  Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4.  Push to the Branch (`git push origin feature/AmazingFeature`)
5.  Open a Pull Request

---

## 📄 License

Distributed under the MIT License. See `LICENSE` for more information.

---

## 📧 Contact

Project Link: [https://github.com/mohamedyussry/juleb-woocommerce-sync](https://github.com/mohamedyussry/juleb-woocommerce-sync)

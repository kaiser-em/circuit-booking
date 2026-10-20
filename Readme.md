# Elite Circuit Booking

A premium, standalone WordPress plugin for booking luxury tours and circuits, designed with a high-end, editorial UI/UX inspired by Apple and Blacklane.

## 📖 About The Project

**Elite Circuit Booking** is an autonomous plugin (separated from Elite Transfer Booking) dedicated exclusively to managing and selling tourist circuits. It provides a seamless, smooth, and highly visual booking experience, focusing on premium interactions and trust-building.

## ✨ Key Features

### 🎨 Premium UI/UX & Frontend
- **Editorial "Magazine" Timeline**: Custom itinerary accordion with smooth slide-down animations.
- **Floating Social Embeds**: Native integration for Instagram, Facebook, and X posts. The embeds float to the right, allowing text to elegantly wrap around them (with Skeleton Loading placeholders for smooth loading).
- **Smart Media Handling**: 
  - Single photos are displayed in a cinematic 16:9 format.
  - Multiple photos are displayed in a compact, swipeable horizontal carousel.
  - Native support for image captions.
- **2-Column Booking Flow**: A modern checkout layout with the form on the left and a sticky, real-time "Your Selection" sidebar on the right.
- **Mobile-First Sticky Dock**: An intelligent floating action bar that hides when reaching the vehicle selection and smoothly scrolls to the precise section title.

### 🛠 Core Architecture & Security
- **Custom Post Types**: Dedicated CPTs for `circuit` and `tour_vehicle`, plus a `circuit_category` taxonomy.
- **Dynamic Pricing Engine**: Real-time calculations based on hourly rates, 10-hour packages, extra hours, and additional options.
- **Security First**: Honeypot anti-spam, secure timestamp tokens, and strict data sanitization on social embeds (`wp_kses`).
- **Dark/Light Mode**: Native theme detection with CSS variables (`etb-tokens.css`).

## 🛠️ Tech Stack

- **Backend**: PHP 8+, WordPress 6.x+
- **Frontend**: Vanilla JavaScript (ES6+), CSS3 (Flexbox, CSS Grid, Custom Properties).
- **Architecture**: Modular PHP classes (Singleton pattern).

## 🚀 Installation

1. Clone or download this repository into your `wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the Circuit settings to configure your vehicles, extras, and general settings.
4. Create your first circuit using the custom meta boxes.

## 📂 Project Structure

```text
elite-circuit-booking/
├── admin/                  # Admin CSS & JS
├── includes/               # Core PHP Classes (Ajax, CPT, Meta, Pricing, Security)
├── public/                 # Frontend CSS, JS, and Fonts
│   └── css/modules/        # Modular CSS (Tokens, Circuits, UI components)
├── templates/              # PHP templates (circuit-view, itinerary, etc.)
├── elite-circuit-booking.php # Main plugin entry point
└── README.md
# PAT Product Emailer

WooCommerce plugin for sending product-targeted customer emails with reusable templates, preview/test sends, and multi-product audience selection.

## Features

- Select one or more WooCommerce products
- Build a reusable email subject, heading, and body template
- Preview the final WooCommerce-styled email in wp-admin
- Send a test email before sending a live campaign
- Deduplicate the audience by billing email across all selected products

## Requirements

- WordPress
- WooCommerce

## Available Tokens

- `{{first_name}}`
- `{{last_name}}`
- `{{full_name}}`
- `{{email}}`
- `{{product_name}}`
- `{{product_url}}`
- `{{product_count}}`
- `{{site_name}}`
- `{{site_url}}`

## Usage

1. Activate the plugin.
2. Open `WooCommerce -> Product Emailer`.
3. Select one or more products.
4. Enter the email subject, heading, and message body.
5. Preview the email.
6. Send a test email.
7. Send the campaign to matching customers.

## Notes

- Recipients are collected from unique billing emails on `processing`, `completed`, and `on-hold` WooCommerce orders.
- The plugin does not manage marketing consent or unsubscribe flows by itself.
- When multiple products are selected, `{{product_name}}` renders as a comma-separated list and `{{product_url}}` uses the first selected product URL.

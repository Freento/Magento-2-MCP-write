# Freento MCP Write Tools for Magento 2 — User Guide

Let AI assistants like Claude and ChatGPT **create and update** store data — products, prices, stock, categories, orders and customers — through the Model Context Protocol (MCP).

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Available Tools](#available-tools)
- [Dry Run Mode](#dry-run-mode)
- [Usage Examples](#usage-examples)
- [Limitations](#limitations)
- [Troubleshooting](#troubleshooting)
- [Security](#security)

## Overview

Freento MCP Write Tools is an add-on for Freento MCP (`freento/module-mcp`). The base module gives AI assistants **read** access to your store; this add-on adds tools that **change** store data. With it, you can:

- Create products and edit their attributes, prices, stock and categories
- Add order comments, create shipments with tracking numbers and issue offline refunds
- Update customer accounts: group, name, email, date of birth, tax/VAT number

Every change goes through Magento service contracts (`ProductRepositoryInterface`, `ShipOrderInterface`, `RefundOrderInterface`, `CustomerRepositoryInterface`, ...), so validation, events, plugins and indexers of Magento and third-party modules run as usual.

**How it works:**

```
┌─────────────────┐         ┌─────────────────┐         ┌─────────────────┐         ┌─────────────────┐
│  AI Assistant   │  HTTP   │  Freento MCP    │         │  MCP Write      │         │   Magento 2     │
│  (Claude/GPT)   │ ◄─────► │  Server         │ ◄─────► │  Tools          │ ◄─────► │ Service Layer   │
└─────────────────┘ JSON-RPC└─────────────────┘         └─────────────────┘         └─────────────────┘
```

Write tools are registered in the Freento MCP tool registry, so they use the same endpoint, OAuth clients, access tokens and ACL roles as the read tools.

**Safety by default:**

- Write tools are **disabled** after installation and must be turned on explicitly
- Each tool must be granted to an ACL role separately
- Every tool supports `dry_run` — preview the change without saving anything
- Every result reports the old and new values, so you can see exactly what changed

## Requirements

- Magento 2.4.x (Open Source or Commerce)
- PHP 8.1 or higher
- Freento MCP (`freento/module-mcp`) 1.2 or higher, installed and configured
- An MCP-compatible AI client (Claude Code, Claude Desktop, or ChatGPT with MCP plugin)

## Installation

### Via Composer (Recommended)

```bash
composer require freento/module-mcp-write
php bin/magento module:enable Freento_McpWrite
php bin/magento setup:upgrade
php bin/magento cache:flush
```

### Manual Installation

1. Make sure Freento MCP is installed and enabled (via Composer or in `app/code/Freento/Mcp/`)
2. Download the module and extract to `app/code/Freento/McpWrite/`
3. Enable the module:

```bash
php bin/magento module:enable Freento_McpWrite
php bin/magento setup:upgrade
php bin/magento cache:flush
```

### Verify Installation

```bash
php bin/magento module:status Freento_McpWrite
```

Expected output: `Module is enabled`

## Configuration

If you have not connected an AI client yet, first follow the Freento MCP User Guide to create an ACL role, an OAuth client and an access token.

### Step 1: Enable Write Tools

1. In Magento Admin, go to **Stores > Configuration > Freento > MCP > MCP Write Tools**
2. Set **Enable Write Tools** to **Yes**
3. **Save Config**

While this setting is **No** (the default), write tools are hidden everywhere: they are not returned by `tools/list`, cannot be called, and do not appear in the ACL role form. It is a single switch that turns all write access off at once.

### Step 2: Grant Tools to an ACL Role

1. Go to **System > Freento MCP > ACL Rules**
2. Open an existing role or click **Add New Role**
3. In the tools list, find the **Freento_McpWrite** group and select only the tools this role needs
4. **Save** the role

> **Tip:** Create a separate role (e.g., "AI Catalog Editor") with only the write tools it needs, instead of adding write tools to a role used for reporting.

### Step 3: Reconnect Your AI Client

The client reads the tool list when it connects. After changing the configuration or the role, reconnect:

- **Claude Code:** run `/mcp` and reconnect the server
- **Claude Desktop:** restart the application
- **ChatGPT and other web clients:** refresh the connector

## Available Tools

### Catalog Tools

| Tool | Description |
|------|-------------|
| `create_product` | Create a simple or virtual product |
| `update_product_attributes` | Update any attribute of a product: status, name, description, visibility, color, custom attributes |
| `update_product_prices` | Update base price, special (sale) price with dates, and tier prices. A new tier price list replaces the existing one |
| `update_stock` | Set stock quantity (an absolute value, not a delta) and in-stock status. With MSI, the default source is updated |
| `assign_product_categories` | Add a product to categories, remove it, or set its exact category list |

### Sales Tools

| Tool | Description |
|------|-------------|
| `add_order_comment` | Add a comment to an order, optionally change its status and email the customer |
| `create_shipment` | Ship an order fully or partially, with tracking numbers |
| `create_creditmemo` | Create an offline credit memo (refund), fully or partially, optionally returning items to stock. No money is sent back through the payment gateway |

### Customer Tools

| Tool | Description |
|------|-------------|
| `update_customer` | Update customer group, name, email, date of birth, tax/VAT number |

Write tools work best together with the read tools of Freento MCP: the AI can look up an order with `get_orders`, find category IDs with `get_categories` or check stock with `get_stock_single_stock` before making a change.

## Dry Run Mode

Every write tool accepts `dry_run`. With `dry_run: true` the tool loads the referenced data, validates the arguments and reports what **would** change — without saving anything:

```
DRY RUN: nothing was saved. Call again with dry_run=false to apply.

Would update prices of 24-MB01 (id 1):
  price: 34.00 -> 29.99
  special_price: (empty) -> 24.99
```

Ask the assistant to "preview", "dry run" or "show what would change" before applying bulk or sensitive changes:

```
"Preview a 10% price increase for all products in category 12, don't save yet"
```

> **Note:** a dry run checks the arguments and the store data they reference. Validation that only happens on save (attribute backend models, third-party plugins) is not run, so a change that passes a dry run can still be rejected when applied.

## Usage Examples

Once configured, you can ask your AI assistant in natural language.

### Products

```
"Disable product 24-MB01"
"Set color Red and size XL for SKU WS03"
"Rename product 24-MB01 to "Joust Duffle Bag" in store view 2"
"Create product NEW-001 "Blue Mug" priced 12.50 with 100 in stock"
"Add virtual product GIFT-50 "Gift card 50" for 50 in category 5"
```

### Prices

```
"Set price of 24-MB01 to 19.99"
"Put 24-MB01 on sale for 14.99 from 2026-10-01 to 2026-10-15"
"Remove the special price from 24-MB01"
"Tier price for 24-MB01: 10+ pcs at 8.50 for Wholesale"
```

### Stock & Categories

```
"Set stock of 24-MB01 to 25"
"Mark 24-MB04 out of stock"
"Add 24-MB01 to category 12"
"Move 24-MB01 to categories 5 and 6 only"
```

### Orders

```
"Add note to order 000000123: customer called, ships Monday"
"Tell the customer of order 000000124 that the item is back-ordered"
"Ship order 000000123 with UPS tracking 1Z999AA10123456784"
"Ship 2 of 24-MB01 from order 000000124 and notify the customer"
"Refund order 000000123 completely and return items to stock"
"Refund 1 x 24-MB01 from order 000000124, no shipping"
```

### Customers

```
"Move customer john@example.com to the Wholesale group"
"Rename customer 42 to Jane Doe"
"Set VAT number DE123456789 for customer jane@example.com"
```

### Combined Read & Write Workflows

The real power comes from combining read tools, AI reasoning and write tools in one conversation:

**Stock correction:**
```
"Find products that are marked in stock but have qty 0, show me the list,
then mark them out of stock"
```

**Seasonal sale:**
```
"Put every product in category 'Summer' on sale at 20% off from
2026-06-01 to 2026-06-30. Show me a dry run first."
```

**Customer segmentation:**
```
"Find customers who spent more than $5,000 this year and move them
to the VIP group"
```

**Fulfillment:**
```
"List processing orders from yesterday that contain only in-stock items,
and ship them with the tracking numbers from this list: ..."
```

For bulk changes, ask the assistant to show the list and a dry run first, and confirm before it applies the changes.

## Limitations

- `create_product` supports simple and virtual products only — configurable, bundle, grouped and downloadable products are not supported
- Product images, customer addresses and invoices are not handled
- Credit memos are always offline; online refunds through the payment gateway must be made in Magento Admin
- Orders cannot be cancelled or placed
- `update_stock` works with the default stock / default MSI source only

## Troubleshooting

### Write tools not appearing in AI assistant

1. Check that **Enable Write Tools** is set to **Yes** in **Stores > Configuration > Freento > MCP > MCP Write Tools**
2. Check that the tools are selected in the ACL role of your OAuth client (**System > Freento MCP > ACL Rules**)
3. Verify the module is enabled:
   ```bash
   php bin/magento module:status Freento_McpWrite
   ```
4. Flush Magento cache:
   ```bash
   php bin/magento cache:flush
   ```
5. Reconnect MCP in your AI client (e.g., `/mcp` in Claude Code)

### "MCP write tools are disabled" error

Write tools were turned off after the client connected. Enable them in **Stores > Configuration > Freento > MCP > MCP Write Tools** and reconnect the client.

### "Access denied" error

The ACL role lacks the tool. Edit the role in **System > Freento MCP > ACL Rules** and select the tool in the **Freento_McpWrite** group.

### A change was rejected

Tool errors explain what was wrong and, where possible, list valid values — for example the allowed order statuses, known website IDs or configured carrier codes. The AI assistant usually corrects the arguments and retries. Common causes:

- The attribute does not belong to the product's attribute set
- A global attribute was set for a single store view — use `store_id` 0
- The order status does not belong to the order's current state
- The order has nothing left to ship or refund
- `special_from_date` is after `special_to_date`

### Test the endpoint manually

```bash
curl -X POST https://your-store.com/freento_mcp/index/index \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"update_stock","arguments":{"sku":"24-MB01","qty":25,"dry_run":true}}}'
```

## Security

Write tools change live store data. Treat an access token with write tools like an admin password.

### Best Practices

1. **Enable only when needed** — Keep **Enable Write Tools** off on stores where AI assistants only need to read data

2. **Minimal Permissions** — Grant only the write tools a role actually needs. A reporting client does not need any write tools

3. **Separate Clients** — Use separate OAuth clients and ACL roles for read-only and write access

4. **Preview First** — Ask the assistant for a dry run before bulk or sensitive changes (prices, refunds, customer data)

5. **Confirm Before Applying** — Review what the assistant is about to change; most AI clients let you approve each tool call

6. **Test on Staging** — Try new workflows on a staging store before using them in production

7. **Customer Notifications** — `notify_customer` is off by default in every tool; the customer receives an email only when you ask for it

8. **Use HTTPS** — Always use HTTPS in production to encrypt API communications

### Token Security

- Never commit tokens to version control
- Use environment variables or secure secret management
- Rotate tokens periodically
- Revoke tokens immediately if compromised

## Support

**Contact:** [https://freento.com/contact](https://freento.com/contact)

## License

MIT License — see [LICENSE](LICENSE) for details.

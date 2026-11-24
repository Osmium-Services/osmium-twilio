---
title: Send text messages with Twilio
tags: sms, text messages, twilio, notifications, orders
page: admin/settings/twilio
---

**Twilio** lets your site send text messages, such as order updates. The service only sends. Whatever wants to text, such as the shop, asks it to. Each text is charged by Twilio.

## Set it up

1. Open **Twilio SMS Settings**.
2. Enter your **Account SID** (starts with AC, on the Twilio Console home page) and **Auth Token** (next to it).
3. Enter the **Sender**: a Twilio number such as +447700900123, an alphanumeric sender ID of up to 11 characters such as your shop name, or a Messaging Service SID (starts MG). UK senders may need registering with Twilio first, and alphanumeric IDs cannot receive replies.
4. Tick to send SMS through Twilio and press **Save Settings**.

## Test it

Under **Send a test text**, enter a **Mobile number** and press **Send test text**. Save first, because it uses the saved settings. It works even while switched off, and is charged like a real text.

## Turn on order texts

In the **Osmium Shop Settings**, under **Customer Notifications**, choose texts for order updates. Only one SMS service can be active at a time. Orders with no phone number are skipped.

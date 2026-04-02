# Delivery Estimate & Holiday Banner

WooCommerce plugin for delivery date calculation, checkout delivery messaging, and holiday banner toggling.

## What It Does

- Adds `Estimated Dispatch` and `Estimated Delivery` output through the `nwd-calc` shortcode.
- Extends WooCommerce shipping labels with delivery estimates for:
  - `Next Working Day`
  - `Standard 3-5`
- Adds a holiday delay note to non-`Click & Collect` shipping methods when a holiday is within the next 5 days.
- Adds the `show-banner` body class when the holiday banner is enabled and a holiday falls within the next 5 days.
- Renders bank holiday messaging through the `nwd-holiday-info` shortcode.

## Installation Notes

- Copy `nwd-delivery-estimate-holiday-banner.php` into your WordPress plugins directory.
- Activate the plugin in WordPress admin.
- Go to `Settings -> Delivery & Holidays`.
- Add holiday dates in `YYYY-MM-DD` format, one per line.

## Important Assumptions

- The plugin does not render a global site banner itself.
- Your theme is responsible for showing or hiding the visible banner when the `show-banner` body class is present.
- Shipping methods are still detected by label text, so the visible WooCommerce method names should continue to include:
  - `Next Working Day`
  - `Standard 3-5`
  - `Click & Collect`

## Settings Behavior

- Holiday dates are normalized on save.
- Invalid dates are ignored and shown as a warning in admin.
- Valid dates are trimmed, deduplicated, and sorted ascending before they are stored.
- The holiday banner toggle saves cleanly when checked or unchecked.

## Shortcodes

- `nwd-calc`
  - Outputs the current dispatch date and next working day delivery date.
- `nwd-holiday-info`
  - Outputs the next upcoming holiday closure and delivery information when the holiday banner setting is enabled.

## Delivery Rules

- Dispatch cutoff is `12:00` in the `Europe/London` timezone.
- Orders after the cutoff move to the next open day.
- Weekends and configured holiday dates are treated as closed days.
- Standard delivery estimates are calculated as 3-5 working days after dispatch.

## Test Checklist

- Save unsorted, duplicate, CRLF-formatted, and invalid holiday entries and confirm the stored value is normalized.
- Confirm checkout estimates show on first load and continue to update after WooCommerce refreshes shipping methods.
- Confirm stores with more than one shipping package still toggle estimates correctly.
- Test consecutive closure days to verify last dispatch and return dates skip both weekends and configured holidays.
- Confirm the `show-banner` body class only appears when the banner is enabled and an upcoming holiday falls within 5 days.
- Confirm storefront copy shows plain punctuation with no broken characters.

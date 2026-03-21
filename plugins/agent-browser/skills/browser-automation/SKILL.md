---
name: agent-browser:browser-automation
description: >
  This skill should be used when the user asks to "navigate to a website",
  "fill a form", "click a button on a page", "take a screenshot of a site",
  "extract data from a web page", "scrape a page", "interact with a browser",
  "automate a browser", "log into a website", or any task requiring web
  browser automation. Provides knowledge of the agent-browser CLI tool for
  programmatic browser control.
---

# Browser Automation with agent-browser

Control a headless browser via the `agent-browser` CLI to navigate websites, interact with elements, extract data, and capture screenshots.

## Core Workflow

Every browser interaction follows this pattern:

1. **Open** a URL
2. **Snapshot** to discover element references
3. **Interact** using refs (fill, click)
4. **Verify** results (screenshot, eval, snapshot)

## Commands Reference

### Navigation

```bash
# Open a URL (launches browser if needed)
agent-browser open <url>

# On Linux, --no-sandbox is often required
agent-browser open <url> --args "--no-sandbox"

# Close the browser (required before relaunching with different options)
agent-browser close

# Connect to an existing browser via Chrome DevTools Protocol
agent-browser connect <port|url>
```

### Discovery

```bash
# Get the accessibility tree with element references (@e1, @e4, etc.)
agent-browser snapshot

# Capture a screenshot (optionally save to file)
agent-browser screenshot [path]
```

The `snapshot` command returns an accessibility tree where each interactive element has a ref like `@e1`, `@e4`, `@e12`. These refs are used as selectors for all interaction commands.

### Interaction

```bash
# Fill a text field
agent-browser fill @ref <value>

# Click an element (button, link, checkbox, etc.)
agent-browser click @ref

# Wait for a selector to appear or a duration in milliseconds
agent-browser wait <selector|ms>
```

### JavaScript Execution

```bash
# Execute arbitrary JavaScript in the page context
agent-browser eval <js>
```

Use `eval` for tasks that go beyond simple fill/click: reading computed styles, extracting structured data, scrolling, or triggering custom events.

## Step-by-Step Procedure

### 1. Open the target page

```bash
agent-browser open https://example.com
```

On Linux, if the browser fails to launch, retry with:
```bash
agent-browser open https://example.com --args "--no-sandbox"
```

### 2. Discover page elements

```bash
agent-browser snapshot
```

Read the accessibility tree output. Identify relevant elements by their role, name, and ref. Example output:

```
@e1 textbox "Email"
@e4 textbox "Password"
@e7 button "Sign In"
@e12 link "Forgot password?"
```

### 3. Interact with elements

Fill fields and click buttons using the refs from the snapshot:

```bash
agent-browser fill @e1 "user@example.com"
agent-browser fill @e4 "<password>"
agent-browser click @e7
```

### 4. Verify the result

After interaction, verify the page state:

```bash
# Take a screenshot to visually confirm
agent-browser screenshot

# Or take a new snapshot to check updated elements
agent-browser snapshot

# Or use JavaScript to extract specific data
agent-browser eval "document.title"
```

### 5. Repeat or close

Continue interacting as needed. When done:

```bash
agent-browser close
```

## Common Patterns

### Form submission

```bash
agent-browser open https://site.com/contact
agent-browser snapshot
# Identify form fields from snapshot output
agent-browser fill @e3 "John Doe"
agent-browser fill @e5 "john@example.com"
agent-browser fill @e8 "Hello, this is my message."
agent-browser click @e10  # Submit button
agent-browser screenshot   # Verify confirmation page
```

### Data extraction

```bash
agent-browser open https://site.com/data
agent-browser eval "JSON.stringify([...document.querySelectorAll('table tr')].map(r => [...r.cells].map(c => c.textContent)))"
```

### Multi-page navigation

```bash
agent-browser open https://site.com
agent-browser snapshot
agent-browser click @e5    # Navigate to subpage
agent-browser wait 1000    # Wait for page load
agent-browser snapshot     # Re-discover elements on new page
```

### Waiting for dynamic content

```bash
agent-browser open https://site.com/dashboard
agent-browser wait ".data-loaded"  # Wait for CSS selector to appear
agent-browser snapshot
```

## Important Notes

- **Refs are ephemeral**: After any navigation or DOM change, take a new `snapshot` to get updated refs. Old refs may no longer be valid.
- **Close before reconfigure**: To change browser launch options (e.g., add `--no-sandbox`), `close` the browser first, then `open` again with new options.
- **Linux sandbox**: On Linux environments, `--args "--no-sandbox"` is commonly needed. Inform the user about this requirement when running on Linux.
- **Screenshots for verification**: Always take a screenshot or snapshot after critical interactions to confirm success before proceeding.
- **JavaScript fallback**: When `fill` or `click` don't work as expected (e.g., custom components, shadow DOM), use `eval` with direct DOM manipulation.

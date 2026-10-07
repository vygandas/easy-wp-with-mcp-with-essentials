# Site theme

Child theme of **Twenty Twenty-Five**, the WordPress 7.1 default. Empty by design. It exists so every override has a home outside the parent, which WordPress replaces wholesale whenever core is updated.

Activating it changes nothing visible: the site renders exactly like the parent until you add something. Rename it (folder, `Theme Name` and `Text Domain` in `style.css`, the `site-style` handle in `functions.php`) if you want your brand on it.

## Where a change belongs

Follow the MCP-first workflow in the repository's `AGENTS.md`. Discover what the MCP server can do and prefer saved configuration for configurable changes. This theme is the fallback for changes that genuinely need code.

| Change | Where |
| --- | --- |
| Colour, font, spacing, per-block defaults | Global Styles and block settings, through MCP |
| Page structure (header, footer, single, archive…) | Stored templates and template parts, through MCP |
| A reusable section an author inserts | Patterns or block content, through MCP |
| Global or per-block CSS that configuration can hold | Global Styles custom CSS, through MCP |
| A custom block or theme behaviour that needs code | Theme or plugin code, after confirming configuration can't do it |
| PHP behaviour | `functions.php` |

## Overriding a template or part

When a code-level override really is needed, copy the parent's file across under the same name and edit the copy:

```bash
cp ../twentytwentyfive/templates/single.html templates/single.html
cp ../twentytwentyfive/parts/header.html parts/header.html
```

WordPress prefers the child's copy; deleting it restores the parent's.

The **Site Editor** saves a database copy that takes precedence over both files. That's the preferred model here, and it survives deployments. Read and keep existing database customizations before overriding anything in code. *Template → Reset* throws a customization away, so don't use it just to make a theme file win.

## theme.json

Routine design changes belong in Global Styles. If a change needs code-level defaults, `theme.json` merges on top of the parent's, so it only needs the keys that differ:

```json
{
	"$schema": "https://schemas.wp.org/wp/6.7/theme.json",
	"version": 3,
	"styles": {
		"elements": {
			"link": { "color": { "text": "var(--wp--preset--color--accent-1)" } }
		}
	}
}
```

`theme.json` is cached per theme version. If a change refuses to appear, bump `Version:` in `style.css`.

## No build step

There is no `package.json` and no theme build. Don't add Tailwind, a Node build stage or generated stylesheets for changes WordPress configuration can handle.

# Web UI (v1.0-alpha) - LOCAL TOOL ONLY

```bash
composer install
php -S localhost:8080 -t web
open http://localhost:8080
```

## Endpoints

| Method | Path | In | Out |
|---|---|---|---|
| GET | `/` | - | Single-page UI: spec editor + M1 upload |
| POST | `/generate` | YAML spec (form field `spec` or raw body) | `application/zip` of the generated module; 422 + plain-text message on spec errors |
| POST | `/extract` | multipart zip of a Magento 1 module (field `m1zip`) | `text/yaml` clean-room spec for human review; 422 on bad input |

## Security posture - read this

**This is a local development tool. It must not be deployed to the
public internet as-is.**

- No authentication.
- No rate limiting.
- No CSRF protection.
- `/extract` unzips user-supplied archives (zip-slip entries are
  rejected, but no size/entry-count limits are enforced).

If you ever want to host it, put it behind auth, add upload limits, and
run the extraction in a sandbox. The underlying library
(`MahoModuleGenerator\Generator`, `M1SpecExtractor`) is pure and safe to
embed; it is this thin HTTP wrapper that is deliberately minimal.

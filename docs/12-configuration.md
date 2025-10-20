# Configuration & Resources
## Configuration

Key `.env` variables:
```env
MOYSKLAD_APP_ID=         # App UUID from developer console
MOYSKLAD_APP_UID=        # App UID (appUid)
MOYSKLAD_SECRET_KEY=     # Secret key from developer console

DB_CONNECTION=pgsql
DB_DATABASE=moysklad_db
DB_USERNAME=moysklad_user
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

CACHE_STORE=redis        # Context caching requires Redis/Memcached
```

## Git Workflow

Format: `<type>: <description>`

Types: `feat`, `fix`, `style`, `refactor`, `docs`

Always commit with descriptive messages including:
```
🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>
```

## Resources

- [МойСклад JSON API 1.2](https://dev.moysklad.ru/doc/api/remap/1.2/)
- [МойСклад Vendor API 1.0](https://dev.moysklad.ru/doc/api/vendor/1.0/)
- [Developer Console](https://apps.moysklad.ru/cabinet/)

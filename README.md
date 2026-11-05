## Запуск проекта

```bash
docker compose up -d --wait && docker compose exec app php artisan migrate:fresh --seed
```

Первый запуск занимает несколько минут. После этого API будет доступно по адресу `http://localhost:8000/api`.

## Запуск тестов

```bash
docker compose exec app php artisan test
```

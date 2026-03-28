# Contribuir

## Reglas mínimas

- Actualiza `VERSION` antes de enviar un cambio a `main`.
- Mantén `CHANGELOG.md` alineado con la nueva versión.
- Verifica sintaxis PHP con `php -l`.
- No subas archivos de `data/` ni secretos locales.

## Flujo recomendado

1. Crea una rama de trabajo.
2. Implementa el cambio.
3. Ajusta `VERSION` según semver.
4. Documenta el cambio en `CHANGELOG.md`.
5. Ejecuta validaciones locales.
6. Haz merge o push a `main`.

## Criterio de semver

- `major`: cambios incompatibles en comportamiento o estructura
- `minor`: nuevas funciones compatibles
- `patch`: fixes, documentación o ajustes internos compatibles

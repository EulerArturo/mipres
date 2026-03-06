# Copilot Instructions

## Regla 1 (obligatoria)
Antes de cualquier inyección, modificación o generación de código, se debe leer línea por línea el archivo o los archivos afectados para evitar bugs, errores y sobreescritura de código existente.

## Alcance
- Aplicar esta regla a archivos PHP, SQL, CSS, JS, JSON y cualquier otro archivo de texto del proyecto.
- Si hay duda sobre impacto en múltiples archivos, leer todos los archivos potencialmente afectados antes de editar.
- Priorizar cambios mínimos, seguros y compatibles con la estructura actual del proyecto.

## Objetivo del proyecto
- Mantener funcionalidad estable en producción.
- Reducir código duplicado sin romper flujos existentes.
- Estandarizar estructura, validaciones, logging y manejo de errores.

## Orden obligatorio de trabajo (Plan Maestro)
1. Auditar código y duplicados.
2. Definir arquitectura modular.
3. Centralizar autenticación y sesión.
4. Unificar cliente API MIPRES.
5. Estandarizar vistas y estilos.
6. Normalizar SQL y migraciones.
7. Agregar validaciones y logs.
8. Pruebas integrales y despliegue.

## Reglas de implementación
- No cambiar comportamiento funcional si no fue solicitado explícitamente.
- No eliminar archivos productivos sin reemplazo validado.
- Evitar duplicación: si una lógica aparece 2+ veces, extraer a función/módulo compartido.
- Cada refactor debe ser incremental, reversible y con alcance controlado.
- Mantener consistencia de nombres, rutas y convenciones existentes.

## Arquitectura recomendada
- `includes/`: autenticación, sesión, utilidades compartidas.
- `services/` (crear si no existe): cliente HTTP/API MIPRES centralizado.
- `config/`: configuración y conexión DB.
- `scripts/`: SQL versionado y scripts de mantenimiento.

## Estándar de cliente API MIPRES
- Toda llamada cURL debe pasar por un cliente común reutilizable.
- Centralizar: timeout, headers, parseo JSON, manejo de HTTP code y errores de red.
- No duplicar construcción de token/URL en múltiples archivos.

## Estándar de logs (obligatorio)
- Registrar eventos de negocio y errores técnicos con contexto mínimo:
	- usuario_id, acción, módulo, detalle, timestamp, ip (si aplica).
- Si el código usa una tabla de log, debe existir en scripts SQL.
- Antes de usar nuevas tablas, crear/actualizar migración SQL correspondiente.

## Estándar SQL y migraciones
- Todo cambio de esquema debe quedar en `scripts/` con archivo versionado.
- Nunca asumir tablas existentes: validar creación en SQL.
- Mantener claves, índices y tipos de datos coherentes con el uso real.

## Validaciones y seguridad
- Validar entradas `POST/GET` en servidor antes de procesar.
- Escapar salida HTML con `htmlspecialchars`.
- Usar consultas preparadas (PDO) sin concatenación insegura.
- No exponer mensajes internos sensibles en pantalla de usuario final.

## Estándar de UI
- Evitar estilos inline nuevos.
- Reusar `assets/styles.css` y componentes visuales consistentes.

## Criterio de terminado por cambio
- Código leído línea por línea en archivos impactados.
- Cambio aplicado con mínimo alcance.
- Sin errores de sintaxis en archivos modificados.
- Flujo funcional principal del módulo validado manualmente.
- Si hubo cambio de BD: script SQL agregado/actualizado.

## Nota de deuda técnica detectada
- El código usa `logs_actividad` y `entregas_reportes_exitosos`, pero en `scripts/` solo está creada `logs_acceso`.
- Antes de pruebas finales, crear migraciones SQL faltantes para evitar fallos en runtime.

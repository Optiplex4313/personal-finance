ESTRUCTURA DE ENTIDADES Y ACCIONES

Expense Tracker - Finanzas Personales

Tecnologías: PostgreSQL 18 + Laravel 12 + Filament PHP 5

ENUMs

```sql
CREATE TYPE estado_gasto_enum      AS ENUM ('PAGADO', 'PENDIENTE', 'REEMBOLSADO', 'BLOQUEADO');
CREATE TYPE tipo_subgasto_enum     AS ENUM ('FIJO', 'VARIABLE_REGULAR', 'VARIABLE_IRREGULAR');
CREATE TYPE recurrencia_enum       AS ENUM ('NINGUNA', 'MENSUAL', 'TRIMESTRAL', 'ANUAL');
CREATE TYPE tipo_movimiento_enum   AS ENUM ('COMPRA', 'CONSUMO', 'AJUSTE', 'PERDIDA');
```

ENTIDADES

gastos

| Columna            | Tipo            | Constraints                          | Descripción                     |
|--------------------|-----------------|--------------------------------------|---------------------------------|
| id                 | UUID            | PRIMARY KEY                          | Identificador único             |
| establecimiento    | VARCHAR(150)    | NOT NULL                             | Nombre del comercio             |
| fecha              | TIMESTAMPTZ     | NOT NULL                             | Fecha y hora real de la compra  |
| total              | NUMERIC(10,2)   | NOT NULL                             | Total pagado                    |
| estado             | estado_gasto_enum | NOT NULL DEFAULT 'PAGADO'          | Estado del pago                 |
| foto_ticket_hash   | VARCHAR(255)    | NULL                                 | Hash o ruta de la foto del ticket |
| creado_en          | TIMESTAMPTZ     | NOT NULL DEFAULT NOW()               | Fecha de creación del registro  |

subgastos

| Columna                  | Tipo              | Constraints                                      | Descripción                              |
|--------------------------|-------------------|--------------------------------------------------|------------------------------------------|
| id                       | UUID              | PRIMARY KEY                                      | Identificador único                      |
| gasto_id                 | UUID              | NOT NULL REFERENCES gastos(id) ON DELETE CASCADE | Relación con el gasto                    |
| producto_id              | UUID              | NULL REFERENCES productos(id) ON DELETE SET NULL | Normalización del producto               |
| concepto                 | VARCHAR(255)      | NOT NULL                                         | Texto exacto del ticket                  |
| cantidad                 | NUMERIC(10,3)     | NOT NULL DEFAULT 1.0                             | Cantidad comprada                        |
| precio_unitario          | NUMERIC(10,2)     | NOT NULL                                         | Precio por unidad                        |
| total_linea              | NUMERIC(10,2)     | GENERATED ALWAYS AS (cantidad * precio_unitario) STORED | Total de la línea                  |
| etiquetas                | JSONB             | NOT NULL DEFAULT '[]'                            | Array de etiquetas                       |
| tipo                     | tipo_subgasto_enum| NOT NULL                                         | Tipo de subgasto                         |
| es_consumible            | BOOLEAN           | NOT NULL DEFAULT true                            | Indica si es consumible                  |
| fecha_fin                | DATE              | NULL                                             | Fecha estimada de próxima compra         |
| recurrencia              | recurrencia_enum  | NOT NULL DEFAULT 'NINGUNA'                       | Tipo de recurrencia                      |
| fecha_proyectada_inicio  | DATE              | NULL                                             | Fecha proyectada de apertura del lote    |
| fecha_real_inicio        | DATE              | NULL                                             | Fecha real de apertura del lote          |

categorias_producto

| Columna      | Tipo         | Constraints                     | Descripción                              |
|--------------|--------------|---------------------------------|------------------------------------------|
| id           | UUID         | PRIMARY KEY                     | Identificador único                      |
| nombre       | VARCHAR(100) | NOT NULL UNIQUE                 | Nombre de la categoría de uso            |
| descripcion  | TEXT         | NULL                            | Descripción opcional                     |
| prioridad    | SMALLINT     | DEFAULT 5 CHECK (prioridad BETWEEN 1 AND 10) | Nivel de prioridad (1-10)          |
| creado_en    | TIMESTAMPTZ  | DEFAULT NOW()                   | Fecha de creación                        |

productos

| Columna                     | Tipo              | Constraints               | Descripción                              |
|-----------------------------|-------------------|---------------------------|------------------------------------------|
| id                          | UUID              | PRIMARY KEY               | Identificador único                      |
| nombre                      | VARCHAR(255)      | NOT NULL                  | Nombre del producto                      |
| marca                       | VARCHAR(100)      | NULL                      | Marca                                    |
| variante                    | VARCHAR(100)      | NULL                      | Variante (sabor, tamaño, etc.)           |
| categoria_id                | UUID              | NOT NULL REFERENCES categorias_producto(id) | Categoría de uso                 |
| unidad_medida               | VARCHAR(20)       | NOT NULL                  | Unidad de medida (kg, l, unidades, etc.) |
| es_consumible               | BOOLEAN           | NOT NULL DEFAULT true     | Indica si es consumible                  |
| cantidad_actual             | NUMERIC(12,3)     | NOT NULL DEFAULT 0.0      | Stock total actual                       |
| tasa_consumo_diaria         | NUMERIC(10,5)     | NULL                      | Tasa de consumo diaria estimada          |
| precio_objetivo             | NUMERIC(10,2)     | NULL                      | Precio objetivo                          |
| notas                       | TEXT              | NULL                      | Notas adicionales                        |
| activo                      | BOOLEAN           | DEFAULT true              | Activo (soft delete)                     |
| fecha_ultima_actualizacion  | TIMESTAMPTZ       | DEFAULT NOW()             | Última actualización del stock           |
| creado_en                   | TIMESTAMPTZ       | DEFAULT NOW()             | Fecha de creación                        |

valoraciones_productos

| Columna         | Tipo         | Constraints                               | Descripción                              |
|-----------------|--------------|-------------------------------------------|------------------------------------------|
| id              | UUID         | PRIMARY KEY                               | Identificador único                      |
| producto_id     | UUID         | NOT NULL REFERENCES productos(id) ON DELETE CASCADE | Producto relacionado              |
| rating_calidad  | NUMERIC(2,1) | CHECK (rating_calidad BETWEEN 1 AND 10)   | Valoración de calidad (1-10)             |
| rating_valor    | NUMERIC(2,1) | CHECK (rating_valor BETWEEN 1 AND 10)     | Valoración calidad/precio (1-10)         |
| comentario      | TEXT         | NULL                                      | Comentario libre                         |
| subgasto_id     | UUID         | NULL REFERENCES subgastos(id) ON DELETE SET NULL | Subgasto asociado (opcional)       |
| fecha           | TIMESTAMPTZ  | DEFAULT NOW()                             | Fecha de la valoración                   |

RELACIONES

- gastos 1 → * subgastos  
- subgastos * → 1 productos  
- productos * → 1 categorias_producto  
- valoraciones_productos * → 1 productos  
- valoraciones_productos * → 0..1 subgastos  

TRIGGERS

```sql
CREATE OR REPLACE FUNCTION actualizar_stock_compra() 
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.producto_id IS NOT NULL AND NEW.es_consumible THEN
        UPDATE productos 
        SET cantidad_actual = cantidad_actual + NEW.cantidad,
            fecha_ultima_actualizacion = NOW()
        WHERE id = NEW.producto_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_subgasto_compra
    AFTER INSERT ON subgastos
    FOR EACH ROW 
    EXECUTE FUNCTION actualizar_stock_compra();
```

ACCIONES DEL SISTEMA

Escrituras

- CreateGastoCompleto (gasto + múltiples subgastos + actualización automática de stock)  
- UpdateGasto  
- DeleteGasto (ON DELETE CASCADE)  
- CreateSubgasto / UpdateSubgasto (permite editar fecha_proyectada_inicio y fecha_real_inicio)  
- CreateProducto / UpdateProducto (incluye tasa_consumo_diaria y cantidad_actual)  
- CreateCategoriaProducto / UpdateCategoriaProducto  
- CreateValoracion / UpdateValoracion  
- RegistrarConsumoManual (resta stock y actualiza fecha_real_inicio)  
- AjustarStockManual (tipo_movimiento_enum)  
- AjustarTasaConsumo  

Lecturas

- ListarGastos (con filtros)  
- ObtenerGastoDetalle  
- HistoricoPreciosProducto  
- EstimacionGastoMesSiguiente (recurrentes + variables + subgastos con fecha_proyectada_inicio en el mes + depleción de stock)  
- CalcularFechaAgotamiento  
- ListarStockBajo  
- CompararProductosPorCategoria  
- ProductosRecomendadosParaRemplazo  
- ObtenerValoracionesProducto  

Tareas en segundo plano (Laravel Scheduler)

- BackupDatabase (diario 03:15 Europe/Madrid)
- RecalcularFechasAgotamiento (diario 03:00)  
- AlertasStockBajo (diario 07:00)  
- ActualizarMediasVariables (diario)  
- SugerenciasRemplazo (semanal)  
- ReporteMensual (primer día del mes)  
- CalculoInflacionPersonal (mensual)  

Los backups de base de datos se guardan en `storage/app/private/backups/postgres` por defecto.

Para hacer backup manual:
"""
docker exec -i personal_finance_db env PGPASSWORD=secret pg_dump -U laravel -d personal_finance | gzip > backup.sql.gz
"""

USUARIOS

Crear un usuario (no modifica uno existente). La contraseña se lee de `PF_ADMIN_PASSWORD` o se pregunta, mínimo 12 caracteres:
"""
php artisan app:ensure-user tu@email.com --name="Tu nombre"
"""

En Celaut, `pf-entrypoint` lo hace al arrancar si se pasan `PF_ADMIN_EMAIL` y `PF_ADMIN_PASSWORD` (`nodo execute -e ...`).

`php artisan db:seed` crea los usuarios de ejemplo con la contraseña de `SEED_PASSWORD`, o con una aleatoria que muestra por consola.

TESTS

Los tests usan PostgreSQL (enums nativos y columnas generadas), no SQLite. Por defecto esperan una base `personal_finance_test` en `127.0.0.1:5432` con usuario `laravel` / `secret` (ver `phpunit.xml`; se puede sobrescribir con variables de entorno `DB_*`):
"""
docker compose up -d db
docker exec -i personal_finance_db psql -U laravel -d personal_finance -c 'CREATE DATABASE personal_finance_test'
DB_PORT=5433 php artisan test
"""

Los tests de `tests/Feature/Payments` cubren el dinero: cuadre de gastos y ajustes, importación de Revolut (estados, reembolsos, comisiones, divisa, duplicados, céntimos), OCR, stock y analíticas por mes en hora local.

Todas las escrituras críticas se ejecutan dentro de transacciones Laravel. Las consultas pesadas utilizan materialized views. El sistema está preparado para Filament Resources y Actions.

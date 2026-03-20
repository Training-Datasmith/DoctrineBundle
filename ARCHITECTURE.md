# DoctrineBundle Architecture

## Purpose

The official Symfony bundle that integrates Doctrine DBAL and Doctrine ORM into
Symfony applications via the DI container, configuration tree, profiler, and
console commands.

## Directory Structure

```
src/
  Doctrine_Bundle.php                    — bundle entry point; registers compiler passes
  Connection_Factory.php                 — creates DBAL connections from config
  Manager_Configurator.php              — configures ORM entity managers
  Attribute/
    As_Doctrine_Listener.php            — #[AsDoctrineListener] service attribute
    As_Entity_Listener.php              — #[AsEntityListener] service attribute
    As_Middleware.php                   — #[AsMiddleware] service attribute
  CacheWarmer/
    Doctrine_Metadata_Cache_Warmer.php  — pre-warms Doctrine metadata cache
  Command/
    Create_Database_Doctrine_Command.php
    Drop_Database_Doctrine_Command.php
    Doctrine_Command.php                — shared base for proxied Doctrine commands
  Controller/
    Profiler_Controller.php             — web profiler panel controller
  DataCollector/
    Doctrine_Data_Collector.php         — collects query data for the profiler
  Dbal/
    Manager_Registry_Aware_Connection_Provider.php
    Regex_Schema_Asset_Filter.php       — filters schema assets by regex
    Schema_Assets_Filter_Manager.php    — manages multiple schema asset filters
  DependencyInjection/
    Configuration.php                   — defines `doctrine` config tree (DBAL + ORM)
    Doctrine_Extension.php              — main extension: loads DBAL + ORM config
    Compiler/                           — various compiler passes (see below)
  Mapping/
    Class_Metadata_Factory.php          — custom metadata factory
    Container_Entity_Listener_Resolver.php  — resolves entity listeners from DI
    Entity_Listener_Service_Resolver.php
    Mapping_Driver.php
  Middleware/
    Backtrace_Debug_Data_Holder.php     — stores query backtraces for the profiler
  Twig/
    Doctrine_Extension.php             — Twig functions/filters for Doctrine
config/
  dbal.php / orm.php / messenger.php / middlewares.php
```

## Compiler Passes

| Pass | Purpose |
|---|---|
| `Cache_Schema_Subscriber_Pass` | registers schema cache event subscribers |
| `Dbal_Schema_Filter_Pass` | wires schema asset filters |
| `Doctrine_Orm_Mappings_Pass` | loads entity mappings from bundles |
| `Entity_Listener_Pass` | registers `#[AsEntityListener]` services |
| `Id_Generator_Pass` | registers custom ID generators |
| `Middlewares_Pass` | wires DBAL middlewares |
| `Service_Repository_Compiler_Pass` | wires service-backed repositories |

## Key Design Decisions

- **PHP 8 attributes**: `#[AsEntityListener]`, `#[AsDoctrineListener]`, and
  `#[AsMiddleware]` reduce YAML/XML service configuration boilerplate.
- **Multi-connection/multi-EM**: the config tree supports defining multiple DBAL
  connections and multiple ORM entity managers, each with isolated config.
- **Profiler integration**: `Doctrine_Data_Collector` captures all executed
  queries, their parameters, and optional backtraces for the Symfony web profiler.
- **Schema asset filters**: configurable regex filters control which database
  objects Doctrine manages, useful in shared-schema setups.

## Extension Points

- Add a custom DBAL type via the `doctrine.dbal.types` config key.
- Add a custom DQL function via `doctrine.orm.dql`.
- Implement `MiddlewareInterface` and tag with `#[AsMiddleware]` for DBAL middleware.
- Implement a custom entity listener and tag with `#[AsEntityListener]`.

## Dependency Flow

```
Symfony Kernel
  └── DoctrineBundle
        ├── DoctrineExtension  → DBAL Connection(s) + ORM EntityManager(s)
        ├── DataCollector      → Symfony Profiler
        └── Console commands   → Doctrine\DBAL\Tools + Doctrine\ORM\Tools
```

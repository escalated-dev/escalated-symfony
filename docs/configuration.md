# Configuration

## Database connection

By default Escalated's entities resolve your application's **default** Doctrine
entity manager. Name a different one to keep the support tables somewhere else —
a schema shared with a legacy system, a multi-tenant split, a separate reporting
store, or simply out of your primary database.

Declare the connection and manager as you normally would, mapping Escalated's
entity namespace to it:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            support:
                url: '%env(resolve:SUPPORT_DATABASE_URL)%'
    orm:
        entity_managers:
            support:
                connection: support
                mappings:
                    Escalated:
                        type: attribute
                        dir: '%kernel.project_dir%/vendor/escalated-dev/escalated-symfony/src/Entity'
                        prefix: 'Escalated\Symfony\Entity'

# config/packages/escalated.yaml
escalated:
    entity_manager: support
```

Leave `entity_manager` unset for the default manager — the historical behaviour,
and what almost every host wants.

### Why the bundle needs the setting at all

Repositories would have followed your mapping on their own:
`ServiceEntityRepository` resolves through `ManagerRegistry::getManagerForClass()`,
which routes by the entity-to-manager mapping above.

The problem is everything else. Sixty-two services in this bundle autowire
`EntityManagerInterface`, and that resolves the **default** manager regardless of
mapping — so without this setting they would all keep writing to your primary
database, silently and without error. `escalated.entity_manager` is aliased from
the option and bound by type in the bundle's `services.yaml`, so every one of
them follows your choice.

**Your user entity does not move.** It belongs to your application and stays on
whichever manager maps it. Escalated stores host user ids as plain unconstrained
columns precisely so the two can live on different connections.

Setting this on an existing install does not move existing data. Migrate the
tables and copy the rows across first.

# `gplanchat/durable-phpstan`

Extension PHPStan pour [`gplanchat/durable`](https://github.com/gplanchat/durable). Elle résout les
appels de `ActivityStub` et `ChildWorkflowStub` depuis leur contrat typé.

```bash
composer require --dev gplanchat/durable-phpstan
```

Avec [`phpstan/extension-installer`](https://github.com/phpstan/extension-installer), rien de plus.
Sinon, dans votre `phpstan.neon` :

```neon
includes:
    - vendor/gplanchat/durable-phpstan/extension.neon
```

## Le problème

Les stubs résolvent leurs appels par `__call()`. Sans extension, PHPStan ne voit que des objets
sans méthode et signale **tous** les appels de stub — les corrects comme les fautifs :

```php
$this->orders->charge($orderId, 100);   // sans extension : « undefined method » — faux
$this->orders->chrage($orderId, 100);   // sans extension : « undefined method » — vrai
```

Le défaut n'est donc pas le silence, c'est le **bruit**. Quatre erreurs dont deux fausses se
mettent en ligne de base ou s'ignorent d'un bloc, et les deux vraies partent avec — ce qui revient
au même que ne rien vérifier, en plus coûteux.

Depuis les décisions **DUR038** et **DUR039**, le stub typé est la *seule* façon de planifier une
activité ou un workflow enfant. Cette vérification-là est donc la seule qui reste.

## Ce que l'extension apporte

Sur la même fixture, mesuré :

| | sans extension | avec |
|---|---|---|
| `charge()` — appel correct | ✗ signalé à tort | ✓ |
| `run()` — enfant, appel correct | ✗ signalé à tort | ✓ |
| `chrage()` — faute de frappe | ✗ | ✗ |
| `helper()` — sans `#[ActivityMethod]` | ✗ | ✗ |
| `charge($id)` — un argument sur deux | *invisible* | ✗ **arité vérifiée** |

La dernière ligne est le gain que le bruit masquait : une fois la méthode connue, PHPStan compare
les arguments à ce que le contrat déclare.

## Ce qu'elle exige de votre code

Le stub porte son contrat en paramètre générique, et PHPStan l'infère depuis
`WorkflowEnvironment::activityStub()`. Encore faut-il qu'il puisse le suivre jusqu'au point
d'appel — ce qui est le cas dès que la propriété est `readonly` et affectée une seule fois, au
constructeur :

```php
private readonly ActivityStub $orders;   // suffit

public function __construct(WorkflowEnvironment $environment)
{
    $this->orders = $environment->activityStub(OrderActivities::class);
}
```

Une propriété **mutable** perd le paramètre entre le constructeur et la méthode. Annotez-la alors
explicitement :

```php
/** @var ActivityStub<OrderActivities> */
private ActivityStub $orders;
```

Dans les deux cas, si le contrat reste introuvable, l'appel est simplement **inconnu** de PHPStan
plutôt qu'accepté à l'aveugle : mieux vaut un faux positif qu'une vérification silencieusement
désactivée.

## Ce qu'elle ne fait pas

Une méthode absente du contrat, ou présente mais sans `#[ActivityMethod]` — respectivement
`#[WorkflowMethod]` pour un enfant — reste inconnue. C'est voulu : le stub la refuse déjà à
l'exécution avec un `BadMethodCallException`, et l'analyse le dit désormais avant.

## Licence

MIT.

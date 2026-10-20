# TCriteria Builder (Adianti Framework Database Bridge - Módulo 13)

### 9. TCriteria Builder (Adianti Framework Database Bridge - Módulo 13)

Traduza qualquer árvore de especificações de domínio puro para instâncias nativas de `Adianti\Database\TCriteria` e `TFilter`, preservando a precedência booleana por aninhamento e aplicando as **Leis de De Morgan** para negações:

```php
use Antevemus\ASpecification\Spec;

// 1. Especificação de domínio com conjunção, disjunção e negação
$spec = Spec::property('active', Spec::equalTo(true))
    ->and(
        Spec::property('salary', Spec::greaterThan(5000))
            ->or(Spec::property('city', Spec::wildcard('New*')))
    )
    ->and(Spec::property('status', Spec::not(Spec::equalTo('CANCELLED'))));

// 2. Compilação direta via Facade estática com mapeamento de colunas
$criteria = Spec::toCriteria(
    specification: $spec,
    fieldMap: [
        'active' => 'st_active',
        'salary' => 'vl_salary',
        'city'   => 'ds_city',
        'status' => 'tp_status'
    ],
    properties: [
        'order'     => 'vl_salary',
        'direction' => 'desc',
        'limit'     => 50,
        'offset'    => 0
    ]
);

echo $criteria->dump();
// '((st_active = TRUE AND (vl_salary > 5000 OR ds_city LIKE 'New%')) AND tp_status <> 'CANCELLED')'

// 3. Invocação direta a partir de qualquer instância de ISpecification
$criteriaFromInstance = $spec->toCriteria();
```

---

Desde a v2.5.0: comparações que ignoram maiúsculas e acentos para um banco declarado (`withAccentFolding()`, `Spec::toCriteria(..., accentFolding:)`), escritas como o `toSql()` as escreve, e subqueries (`inSubquery()`, `existsIn()`) no lugar de `NOESC:(SELECT ...)`; as duas exigem conexão com `prep = 1` (exemplo 22.1). Valores que começam com `(SELECT`, contêm `{session.` ou começam com `NOESC:` continuam recusados.

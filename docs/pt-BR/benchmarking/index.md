# Telemetria e Benchmarking

### 6. Telemetria e Benchmarking

```php
use Antevemus\ASpecification\Helpers\StopWatch;
use Antevemus\ASpecification\Helpers\InstrumentationUtils;

$watch = StopWatch::createStarted();

// Executa operação de alta volumetria
$entities = $repo->findAll($complexSpec);

$watch->stop();
echo "Query executed in: " . $watch->formatElapsed() . "\n";
echo InstrumentationUtils::formatMemoryUsage() . "\n";
```

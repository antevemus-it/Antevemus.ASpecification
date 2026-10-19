# High-Precision Benchmarking & Telemetry

### 6. High-Precision Benchmarking & Telemetry

```php
use Antevemus\ASpecification\Helpers\StopWatch;
use Antevemus\ASpecification\Helpers\InstrumentationUtils;

$watch = StopWatch::createStarted();

$entities = $repo->findAll($complexSpec);

$watch->stop();
echo "Query executed in: " . $watch->formatElapsed() . "\n";
echo InstrumentationUtils::formatMemoryUsage() . "\n";
```

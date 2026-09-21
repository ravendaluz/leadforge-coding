# LeadForge Validation Service

This project is a small Laravel component for validating leads through a fake external provider.

It covers the main parts from the exercise:

- provider integration
- retry handling for temporary failures
- idempotent result storage
- tests for both normal validation flow and concurrent workers

### Setup

#### Requirements

- PHP 8.2+
- Composer
- SQLite support in PHP

#### Install

Fastest option:

```bash
composer setup
```

Or run the steps manually:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Running tests

Run everything:

```bash
php artisan test
```

Run only the validation-related tests:

```bash
php artisan test --filter=LeadValidationServiceTest
php artisan test --filter=LeadValidationConcurrencyTest
```

The tests are deterministic. They use `Http::fake()` and do not call a real external service.

Run linting and static analysis:

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

### Example usage

The service can be resolved from the container because `AppServiceProvider` binds:

- `ValidationProvider` -> `FakeValidationProvider`
- `LeadValidationResultRepository` -> `EloquentLeadValidationResultRepository`

```php
use App\Models\Lead;
use App\Services\Validation\LeadValidationService;
use App\Services\Validation\ValidationVerdict;

$lead = Lead::create([
    'email' => 'janedoe@lead.com',
    'phone' => '+1234000000',
    'country' => 'US',
]);

$service = app(LeadValidationService::class);
$result = $service->validate($lead);

if ($result->verdict === ValidationVerdict::VALID) {
    echo "Lead #{$result->leadId} is valid after {$result->attempts} attempt(s).";
} elseif ($result->verdict === ValidationVerdict::INVALID) {
    echo "Lead #{$result->leadId} is invalid.";
} else {
    echo "Lead #{$result->leadId} could not be validated.";
}

$reusedResult = $service->validate($lead);
assert($reusedResult->reused === true);
```

### Main pieces

- `LeadValidationService` - main orchestration
- `ValidationProvider` - provider contract
- `FakeValidationProvider` - provider adapter used in this exercise
- `LeadValidationResultRepository` - persistence contract
- `EloquentLeadValidationResultRepository` - Eloquent implementation
- `ValidationResult` - returned result object
- `ValidationVerdict` - `valid`, `invalid`, `unknown`
- `ProviderResponse` - mapped provider response
- `RetryPolicy` - max attempts and backoff

### Out of scope

I kept this focused on the exercise, so I did not add:

- HTTP endpoints
- queues
- domain events
- full production monitoring / infrastructure

### Design notes

#### How does the implementation guarantee idempotency?

Each validation result is stored once per `(lead_id, provider_name)`. That is enforced with a unique database
constraint.

Inside the validation transaction, the service reloads the specific `leads` row with `lockForUpdate()`, then checks
whether a validation result already exists for that lead/provider pair. If a completed result already exists, it is
returned immediately and the provider is not called again.

If no result exists yet, the service writes a pending result before entering the provider retry loop. That means the
reservation happens before the external call.

#### What happens if two workers process the same lead at the same time?

The intended flow is that both workers contend for the same lead row first.

The first worker gets the lock, creates the pending result if needed, calls the provider, saves the final result, and
commits.

The second worker only continues after that transaction finishes. At that point it sees the stored result and returns it
as reused instead of starting a second provider call, even if the completed verdict was `unknown`.

SQLite is a bit different here because it can raise `database is locked` during concurrent writes instead of behaving
like a typical row-locking database. For this project, that case is handled as concurrency contention: the service waits
briefly, re-reads the stored result, and reuses it.

There is also a dedicated concurrency test that starts two separate worker processes against the same SQLite file. One
worker is configured to become `valid` after 3 attempts and the other to become `invalid` after 2. The test proves that
only one worker actually consumes its retry plan, and that later runs reuse the same stored result.

#### Which errors are retryable and why?

The provider adapter treats these as temporary failures: `HTTP 429`, `HTTP 503`, and connection timeout. These are the
cases most likely to succeed on another attempt.

The service does not retry on definitive `valid` or `invalid` responses, explicit `unknown` responses, malformed
responses, and other failures that do not clearly look temporary. In those cases the completed result is stored once and
reused on later duplicate requests.

After retries are exhausted, the final verdict is `unknown`.

For debugging, the stored metadata keeps only a small reason/status payload for unknown or retry-exhausted outcomes,
plus an `exhausted` flag when the retry limit is hit.

The default retry strategy is exponential backoff at `100ms`, `200ms`, and `400ms` with a maximum of 3 attempts.

#### How would you scale this to millions of leads?

For the exercise, synchronous validation is enough.

If volume grows beyond what synchronous requests can handle, the first change I would make is moving validation into
queued jobs so lead intake is not blocked by provider latency. That is mainly about decoupling request handling from
provider response time. I would keep the unique `(lead_id, provider_name)` constraint and the idempotent reservation
flow.

At much larger scale, I would address throughput and storage limits by partitioning / sharding validation data and
running workers across multiple nodes. That is a separate concern from queueing itself, queueing protects the request
path, while sharding and horizontal workers increase total system capacity.

#### What would you change before putting it into production?

For the near term:

- structured logging and metrics around retries, provider latency, and outcomes
- queue-based validation
- a circuit breaker or provider health guard to handle sustained provider outages
- API authentication for outbound requests and rate limit handling
- tooling for manual inspection and replay of failed validations

For later:

- support for more than one provider
- dashboards and alerting
- an API endpoint for submission and status lookup

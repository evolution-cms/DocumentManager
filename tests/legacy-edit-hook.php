<?php

declare(strict_types=1);

namespace EvolutionCMS\DocumentManager\Services\Documents;

use EvolutionCMS\DocumentManager\Services\DocumentManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use RuntimeException;
use Tests\Support\DocumentSaveDatabase;

// Run with: php tests/legacy-edit-hook.php /path/to/evolution/core
// Requires a 3.5.x checkout with Composer dependencies and pdo_sqlite.
$core = $argv[1] ?? '';
if (!is_file($core . '/vendor/autoload.php') || !extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Pass the Evolution CMS core directory with vendor dependencies; enable pdo_sqlite.');
}
require $core . '/vendor/autoload.php';
// Load the package sources under test before Evolution's vendored copy.
spl_autoload_register(static function (string $class): void {
    $prefix = 'EvolutionCMS\\DocumentManager\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
}, prepend: true);
require $core . '/tests/Support/DocumentSaveDatabase.php';

function EvolutionCMS(): object
{
    static $cms;
    return $cms ??= new class {
        public array $events = [];
        public bool $failBeforeSave = false;
        public bool $canPublish = true;

        public function timestamp(int $timestamp): int
        {
            return $timestamp;
        }

        public function getLoginUserID(): int
        {
            return 7;
        }

        public function getConfig(string $key): int
        {
            return 0;
        }

        public function getParentIds(int $id): array
        {
            return [];
        }

        public function hasPermission(string $permission): bool
        {
            return $this->canPublish;
        }

        public function invokeEvent(string $name, array $args): void
        {
            $id = $args['user'] ?? $args['id'];
            $query = Capsule::table('site_content')->where('id', $id);
            $this->events[] = [$name, Capsule::connection()->transactionLevel(), $query->value('pagetitle')];

            if ($name === 'OnBeforeDocFormSave') {
                $query->update(['pagetitle' => 'Plugin title', 'description' => 'Plugin description']);
                if ($this->failBeforeSave) {
                    throw new RuntimeException('Stop the save');
                }
            }
        }
    };
}

$capsule = DocumentSaveDatabase::boot();
$translator = new Translator(new ArrayLoader(), 'en');
$capsule->getContainer()->instance('translator', $translator);
$capsule->getContainer()->instance('validator', new Factory($translator));
if (!class_exists('Validator')) {
    class_alias(ValidatorFacade::class, 'Validator');
}
Capsule::table('site_content')->insert([
    ['id' => 2, 'pagetitle' => 'Manager title', 'description' => 'Original description', 'published' => 1],
    ['id' => 3, 'pagetitle' => 'Original title', 'description' => 'Original description', 'published' => 0],
]);

$service = new DocumentManager();
$form = ['id' => 2, 'pagetitle' => 'Manager title', 'alias' => '', 'published' => 0];
EvolutionCMS()->canPublish = false;
$result = $service->edit($form, cache: false);
$stored = Capsule::table('site_content')->find(2);
if ($stored->pagetitle !== 'Manager title' || $result->pagetitle !== 'Manager title') {
    throw new RuntimeException('The submitted title must override the plugin title even when it matches the pre-hook value.');
}
if ($stored->description !== 'Plugin description' || $result->description !== 'Plugin description') {
    throw new RuntimeException('The plugin change to an omitted field must survive.');
}
if ((int)$stored->published !== 1) {
    throw new RuntimeException('The manager without publish permission must not change the publication state.');
}
if (EvolutionCMS()->events !== [['OnBeforeDocFormSave', 1, 'Manager title'], ['OnDocFormSave', 0, 'Manager title']]) {
    throw new RuntimeException('The before hook must run once inside the transaction and the after hook must see the committed title.');
}

EvolutionCMS()->events = [];
EvolutionCMS()->failBeforeSave = true;
try {
    $service->edit(['id' => 3] + $form, cache: false);
    throw new RuntimeException('The edit must propagate the failure from the before hook.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Stop the save') {
        throw $error;
    }
}
$stored = Capsule::table('site_content')->find(3);
if ($stored->pagetitle !== 'Original title' || $stored->description !== 'Original description'
    || EvolutionCMS()->events !== [['OnBeforeDocFormSave', 1, 'Original title']]) {
    throw new RuntimeException('A failed edit must roll back plugin writes and must not fire OnDocFormSave.');
}

echo "Legacy edit hook regression passed.\n";

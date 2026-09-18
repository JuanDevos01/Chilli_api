<?php

namespace App\Domain\Shared\EventSerializers;

use App\Domain\Shared\Attributes\Encrypted;
use Illuminate\Support\Facades\Crypt;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Spatie\EventSourcing\EventSerializers\EventSerializer;
use Spatie\EventSourcing\EventSerializers\JsonEventSerializer;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EncryptedEventSerializer implements EventSerializer
{
    private JsonEventSerializer $inner;

    public function __construct()
    {
        $this->inner = new JsonEventSerializer();
    }

    public function serialize(ShouldBeStored $event): string
    {
        $json = $this->inner->serialize($event);
        $data = json_decode($json, true) ?? [];

        foreach ($this->encryptedProperties(new ReflectionClass($event)) as $property) {
            $name = $property->getName();
            if (! array_key_exists($name, $data) || $data[$name] === null) {
                continue;
            }

            $plaintext = is_array($data[$name]) ? json_encode($data[$name]) : (string) $data[$name];
            $data[$name] = Crypt::encryptString($plaintext);
        }

        return json_encode($data);
    }

    public function deserialize(
        string $eventClass,
        string $json,
        int $version,
        ?string $metadata = null
    ): ShouldBeStored {
        $data = json_decode($json, true) ?? [];
        $reflection = new ReflectionClass($eventClass);

        foreach ($this->encryptedProperties($reflection) as $property) {
            $name = $property->getName();
            if (! array_key_exists($name, $data) || ! is_string($data[$name]) || $data[$name] === '') {
                continue;
            }

            $plaintext = Crypt::decryptString($data[$name]);
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
                $data[$name] = json_decode($plaintext, true) ?? [];
            } else {
                $data[$name] = $plaintext;
            }
        }

        return $this->inner->deserialize($eventClass, json_encode($data), $version, $metadata);
    }

    /**
     * @return ReflectionProperty[]
     */
    private function encryptedProperties(ReflectionClass $reflection): array
    {
        return array_values(array_filter(
            $reflection->getProperties(),
            fn (ReflectionProperty $p) => count($p->getAttributes(Encrypted::class)) > 0,
        ));
    }
}

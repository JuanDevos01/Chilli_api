<?php

namespace App\Domain\Shared\EventSerializers;

use App\Domain\Shared\Attributes\Encrypted;
use Illuminate\Support\Facades\Crypt;
use ReflectionClass;
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
        $properties = $this->encryptedProperties($event);
        $original = [];

        foreach ($properties as $property) {
            $value = $property->getValue($event);
            $original[$property->getName()] = $value;
            $property->setValue($event, Crypt::encryptString((string) $value));
        }

        try {
            return $this->inner->serialize($event);
        } finally {
            foreach ($properties as $property) {
                $property->setValue($event, $original[$property->getName()]);
            }
        }
    }

    public function deserialize(
        string $eventClass,
        string $json,
        int $version,
        ?string $metadata = null
    ): ShouldBeStored {
        $event = $this->inner->deserialize($eventClass, $json, $version, $metadata);

        foreach ($this->encryptedProperties($event) as $property) {
            $value = $property->getValue($event);

            if (is_string($value) && $value !== '') {
                $property->setValue($event, Crypt::decryptString($value));
            }
        }

        return $event;
    }

    /**
     * @return ReflectionProperty[]
     */
    private function encryptedProperties(object $event): array
    {
        $reflection = new ReflectionClass($event);

        return array_values(array_filter(
            $reflection->getProperties(),
            fn (ReflectionProperty $p) => count($p->getAttributes(Encrypted::class)) > 0,
        ));
    }
}

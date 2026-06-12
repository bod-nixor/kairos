<?php
declare(strict_types=1);

interface DriveStorageInterface
{
    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function upload(array $file, array $context): array;

    /**
     * @return array<string,mixed>
     */
    public function getMetadata(string $fileId): array;

    /**
     * @param resource $destination
     * @return array<string,mixed>
     */
    public function downloadToStream(string $fileId, $destination): array;

    /**
     * @param array<string,string|int> $properties
     */
    public function updateAppProperties(string $fileId, array $properties): void;

    public function delete(string $fileId): void;
}

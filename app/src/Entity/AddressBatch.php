<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An administrator address batch (`address_batches`, specification 2.11). A read-only mapping: the
 * services write this table with DBAL (App\System, App\AddressBatch).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'address_batches')]
class AddressBatch
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'client_id', type: 'uuid')]
    private Uuid $clientId;

    #[ORM\Column(name: 'name', type: 'text')]
    private string $name;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'source', type: 'text', nullable: true)]
    private ?string $source = null;

    #[ORM\Column(name: 'purpose', type: 'text')]
    private string $purpose;

    #[ORM\Column(name: 'original_filename', type: 'text')]
    private string $originalFilename;

    #[ORM\Column(name: 'file_format', type: 'text')]
    private string $fileFormat;

    #[ORM\Column(name: 'email_column', type: 'text', nullable: true)]
    private ?string $emailColumn = null;

    #[ORM\Column(name: 'file_sha256', type: 'text')]
    private string $fileSha256;

    #[ORM\Column(name: 'uploaded_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $uploadedByUserId = null;

    #[ORM\Column(name: 'created_at', type: 'timestamptz')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'data_rows', type: 'integer')]
    private int $dataRows;

    #[ORM\Column(name: 'imported_count', type: 'integer')]
    private int $importedCount;

    #[ORM\Column(name: 'duplicate_count', type: 'integer')]
    private int $duplicateCount;

    #[ORM\Column(name: 'malformed_count', type: 'integer')]
    private int $malformedCount;

    #[ORM\Column(name: 'blank_count', type: 'integer')]
    private int $blankCount;

    #[ORM\Column(name: 'list_id', type: 'text', nullable: true)]
    private ?string $listId = null;

    #[ORM\Column(name: 'compliance_approved_at', type: 'timestamptz', nullable: true)]
    private ?\DateTimeImmutable $complianceApprovedAt = null;

    #[ORM\Column(name: 'compliance_approved_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $complianceApprovedByUserId = null;

    #[ORM\Column(name: 'compliance_reference', type: 'text', nullable: true)]
    private ?string $complianceReference = null;

    private function __construct()
    {
    }

    public function getId(): Uuid { return $this->id; }
    public function getClientId(): Uuid { return $this->clientId; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getSource(): ?string { return $this->source; }
    public function getPurpose(): string { return $this->purpose; }
    public function getOriginalFilename(): string { return $this->originalFilename; }
    public function getFileFormat(): string { return $this->fileFormat; }
    public function getEmailColumn(): ?string { return $this->emailColumn; }
    public function getFileSha256(): string { return $this->fileSha256; }
    public function getUploadedByUserId(): ?Uuid { return $this->uploadedByUserId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDataRows(): int { return $this->dataRows; }
    public function getImportedCount(): int { return $this->importedCount; }
    public function getDuplicateCount(): int { return $this->duplicateCount; }
    public function getMalformedCount(): int { return $this->malformedCount; }
    public function getBlankCount(): int { return $this->blankCount; }
    public function getListId(): ?string { return $this->listId; }
    public function getComplianceApprovedAt(): ?\DateTimeImmutable { return $this->complianceApprovedAt; }
    public function getComplianceApprovedByUserId(): ?Uuid { return $this->complianceApprovedByUserId; }
    public function getComplianceReference(): ?string { return $this->complianceReference; }
}

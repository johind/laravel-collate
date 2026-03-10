<?php

namespace Johind\Collate;

readonly class PdfMetadata
{
    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?string $subject = null,
        public ?string $keywords = null,
        public ?string $creator = null,
        public ?string $producer = null,
        public ?string $creationDate = null,
        public ?string $modDate = null,
    ) {}

    /**
     * Create a metadata instance from qpdf JSON output.
     *
     * @param  array<string, string>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['Title'] ?? $data['title'] ?? null,
            author: $data['Author'] ?? $data['author'] ?? null,
            subject: $data['Subject'] ?? $data['subject'] ?? null,
            keywords: $data['Keywords'] ?? $data['keywords'] ?? null,
            creator: $data['Creator'] ?? $data['creator'] ?? null,
            producer: $data['Producer'] ?? $data['producer'] ?? null,
            creationDate: $data['CreationDate'] ?? $data['creationDate'] ?? null,
            modDate: $data['ModDate'] ?? $data['modDate'] ?? null,
        );
    }

    /**
     * Convert the metadata to an array of non-null values.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'Title' => $this->title,
            'Author' => $this->author,
            'Subject' => $this->subject,
            'Keywords' => $this->keywords,
            'Creator' => $this->creator,
            'Producer' => $this->producer,
            'CreationDate' => $this->creationDate,
            'ModDate' => $this->modDate,
        ], fn ($value) => $value !== null);
    }
}

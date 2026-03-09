<?php

namespace Johind\Collate;

class PdfMetadata
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $author = null,
        public readonly ?string $subject = null,
        public readonly ?string $keywords = null,
        public readonly ?string $creator = null,
        public readonly ?string $producer = null,
        public readonly ?string $creationDate = null,
        public readonly ?string $modDate = null,
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
        ], fn ($value) => $value !== null);
    }
}

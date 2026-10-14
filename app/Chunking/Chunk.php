<?php

declare(strict_types=1);

namespace App\Chunking;

final readonly class Chunk
{
    /**
     * @param  string  $anchor  Unique within the file; split parts get a "-part-N" suffix.
     * @param  string  $sectionAnchor  The anchor of the section on laravel.com, shared by every part.
     */
    public function __construct(
        public string $heading,
        public string $headingPath,
        public string $anchor,
        public string $sectionAnchor,
        public string $content,
        public int $tokenCount,
        public string $hash,
    ) {}

    /**
     * The text sent to the embedding model: the heading path gives each chunk its place in the docs.
     */
    public function embeddingText(): string
    {
        return $this->headingPath."\n\n".$this->content;
    }
}

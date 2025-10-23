<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251015214603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE movie_list_item ADD movie_list_id INT NOT NULL');
        $this->addSql('ALTER TABLE movie_list_item ADD CONSTRAINT FK_C900F3F11D3854A5 FOREIGN KEY (movie_list_id) REFERENCES movie_list (id)');
        $this->addSql('CREATE INDEX IDX_C900F3F11D3854A5 ON movie_list_item (movie_list_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE movie_list_item DROP FOREIGN KEY FK_C900F3F11D3854A5');
        $this->addSql('DROP INDEX IDX_C900F3F11D3854A5 ON movie_list_item');
        $this->addSql('ALTER TABLE movie_list_item DROP movie_list_id');
    }
}

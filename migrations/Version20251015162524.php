<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251015162524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE movie_list ADD user_id INT NOT NULL, ADD description LONGTEXT DEFAULT NULL, ADD items JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE movie_list ADD CONSTRAINT FK_B7AED915A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_B7AED915A76ED395 ON movie_list (user_id)');
        $this->addSql('ALTER TABLE user DROP is_verified');
        $this->addSql('ALTER TABLE user RENAME INDEX uniq_identifier_email TO UNIQ_8D93D649E7927C74');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD is_verified TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE user RENAME INDEX uniq_8d93d649e7927c74 TO UNIQ_IDENTIFIER_EMAIL');
        $this->addSql('ALTER TABLE movie_list DROP FOREIGN KEY FK_B7AED915A76ED395');
        $this->addSql('DROP INDEX IDX_B7AED915A76ED395 ON movie_list');
        $this->addSql('ALTER TABLE movie_list DROP user_id, DROP description, DROP items');
    }
}

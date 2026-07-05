<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704180145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE org_users ADD unique_id_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_org_unique_hash ON org_users (organization_id, unique_id_hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uq_org_unique_hash ON org_users');
        $this->addSql('ALTER TABLE org_users DROP unique_id_hash');
    }
}

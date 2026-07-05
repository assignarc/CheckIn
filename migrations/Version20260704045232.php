<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704045232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Drop old foreign keys and indexes on work_sessions
        $this->addSql('ALTER TABLE work_sessions DROP FOREIGN KEY `FK_4FD5174F8C03F15C`');
        $this->addSql('DROP INDEX IDX_4FD5174F8C03F15C ON work_sessions');
        
        // Drop old foreign key on employees
        $this->addSql('ALTER TABLE employees DROP FOREIGN KEY `FK_BA82C30032C8A3DE`');
        
        // Rename the table
        $this->addSql('RENAME TABLE employees TO org_users');

        // Add new foreign key on org_users
        $this->addSql('ALTER TABLE org_users ADD CONSTRAINT FK_EACE568B32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE');

        // Rename the column in work_sessions
        $this->addSql('ALTER TABLE work_sessions CHANGE employee_id org_user_id INT NOT NULL');
        $this->addSql('ALTER TABLE work_sessions ADD CONSTRAINT FK_4FD5174FD411875B FOREIGN KEY (org_user_id) REFERENCES org_users (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_4FD5174FD411875B ON work_sessions (org_user_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop new foreign keys
        $this->addSql('ALTER TABLE work_sessions DROP FOREIGN KEY FK_4FD5174FD411875B');
        $this->addSql('DROP INDEX IDX_4FD5174FD411875B ON work_sessions');
        
        $this->addSql('ALTER TABLE org_users DROP FOREIGN KEY FK_EACE568B32C8A3DE');
        
        // Rename table back
        $this->addSql('RENAME TABLE org_users TO employees');

        // Add old foreign key
        $this->addSql('ALTER TABLE employees ADD CONSTRAINT `FK_BA82C30032C8A3DE` FOREIGN KEY (organization_id) REFERENCES organizations (id) ON UPDATE NO ACTION ON DELETE CASCADE');

        // Rename column back
        $this->addSql('ALTER TABLE work_sessions CHANGE org_user_id employee_id INT NOT NULL');
        $this->addSql('ALTER TABLE work_sessions ADD CONSTRAINT `FK_4FD5174F8C03F15C` FOREIGN KEY (employee_id) REFERENCES employees (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_4FD5174F8C03F15C ON work_sessions (employee_id)');
    }
}

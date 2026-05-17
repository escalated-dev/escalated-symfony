<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin skills management: skills master, routing joins, agent proficiency junction.
 *
 * @see https://github.com/escalated-dev/escalated-developer-context/blob/main/domain-model/skills-management.md
 */
final class Version20260517000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_skills, escalated_skill_routing_tags, escalated_skill_routing_departments, escalated_agent_skills';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_skills (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_escalated_skills_slug (slug),
            UNIQUE INDEX UNIQ_escalated_skills_name (name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE escalated_skill_routing_tags (
            id INT AUTO_INCREMENT NOT NULL,
            skill_id INT NOT NULL,
            tag_id INT NOT NULL,
            INDEX IDX_skill_routing_tag_skill (skill_id),
            INDEX IDX_skill_routing_tag_tag (tag_id),
            UNIQUE INDEX UNIQ_skill_routing_tag (skill_id, tag_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_skill_routing_tags ADD CONSTRAINT FK_skill_routing_tag_skill FOREIGN KEY (skill_id) REFERENCES escalated_skills (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE escalated_skill_routing_tags ADD CONSTRAINT FK_skill_routing_tag_tag FOREIGN KEY (tag_id) REFERENCES escalated_tags (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE escalated_skill_routing_departments (
            id INT AUTO_INCREMENT NOT NULL,
            skill_id INT NOT NULL,
            department_id INT NOT NULL,
            INDEX IDX_skill_routing_dept_skill (skill_id),
            INDEX IDX_skill_routing_dept_dept (department_id),
            UNIQUE INDEX UNIQ_skill_routing_dept (skill_id, department_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_skill_routing_departments ADD CONSTRAINT FK_skill_routing_dept_skill FOREIGN KEY (skill_id) REFERENCES escalated_skills (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE escalated_skill_routing_departments ADD CONSTRAINT FK_skill_routing_dept_dept FOREIGN KEY (department_id) REFERENCES escalated_departments (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE escalated_agent_skills (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            skill_id INT NOT NULL,
            proficiency SMALLINT NOT NULL DEFAULT 3,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_agent_skills_skill (skill_id),
            INDEX IDX_agent_skills_user (user_id),
            UNIQUE INDEX UNIQ_agent_skills_user_skill (user_id, skill_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_agent_skills ADD CONSTRAINT FK_agent_skills_skill FOREIGN KEY (skill_id) REFERENCES escalated_skills (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE escalated_agent_skills ADD CONSTRAINT CHK_agent_skills_proficiency CHECK (proficiency >= 1 AND proficiency <= 5)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalated_agent_skills DROP FOREIGN KEY FK_agent_skills_skill');
        $this->addSql('DROP TABLE escalated_agent_skills');
        $this->addSql('ALTER TABLE escalated_skill_routing_departments DROP FOREIGN KEY FK_skill_routing_dept_skill');
        $this->addSql('ALTER TABLE escalated_skill_routing_departments DROP FOREIGN KEY FK_skill_routing_dept_dept');
        $this->addSql('DROP TABLE escalated_skill_routing_departments');
        $this->addSql('ALTER TABLE escalated_skill_routing_tags DROP FOREIGN KEY FK_skill_routing_tag_skill');
        $this->addSql('ALTER TABLE escalated_skill_routing_tags DROP FOREIGN KEY FK_skill_routing_tag_tag');
        $this->addSql('DROP TABLE escalated_skill_routing_tags');
        $this->addSql('DROP TABLE escalated_skills');
    }
}

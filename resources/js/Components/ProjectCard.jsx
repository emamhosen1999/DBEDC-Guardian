import React from 'react';
import { Card, Flex, Text, Badge, Box } from '@radix-ui/themes';

const ProjectCard = ({ project, onClick, ...rest }) => (
  <Card onClick={onClick} style={{ cursor: onClick ? 'pointer' : undefined }}>
    <Flex direction="column" gap="2">
      <Flex align="center" justify="between">
        <Text size="3" weight="bold">{project?.name || project?.title || 'Untitled'}</Text>
        {project?.status && <Badge color={project.status === 'active' ? 'green' : 'gray'}>{project.status}</Badge>}
      </Flex>
      {project?.description && <Text size="2" color="gray">{project.description}</Text>}
    </Flex>
  </Card>
);

export default ProjectCard;

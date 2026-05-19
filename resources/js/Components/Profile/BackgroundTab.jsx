import React from 'react';
import { Grid, Box } from '@radix-ui/themes';
import EducationInformationDialog from "@/Forms/EducationInformationForm.jsx";
import ExperienceInformationForm from "@/Forms/ExperienceInformationForm.jsx";

const BackgroundTab = ({ user, setUser, canEdit }) => {
    return (
        <Grid columns={{ initial: '1', lg: '2' }} gap="5">
            {/* Education History List + Edit */}
            <EducationInformationDialog user={user} setUser={setUser} inlineMode={true} />
            
            {/* Work Experience List + Edit */}
            <ExperienceInformationForm user={user} setUser={setUser} inlineMode={true} />
        </Grid>
    );
};
export default BackgroundTab;
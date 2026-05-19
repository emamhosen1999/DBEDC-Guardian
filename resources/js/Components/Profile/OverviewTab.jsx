import React, { useState } from 'react';
import { Grid } from '@radix-ui/themes';
import PersonalInformationForm from "@/Forms/PersonalInformationForm.jsx";
import EmergencyContactForm from "@/Forms/EmergencyContactForm.jsx";
import BankInformationForm from "@/Forms/BankInformationForm.jsx";

// This component acts as the "Manager" for all personal/contact cards
const OverviewTab = ({ user, setUser, canEdit }) => {
    return (
        <Grid columns={{ initial: '1', lg: '2' }} gap="5">
            {/* These forms are now refactored to be Card-based inline components */}
            <PersonalInformationForm user={user} setUser={setUser} inlineMode={true} />
            <EmergencyContactForm user={user} setUser={setUser} inlineMode={true} />
            <BankInformationForm user={user} setUser={setUser} inlineMode={true} />
        </Grid>
    );
};

export default OverviewTab;
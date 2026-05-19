import React from 'react';
import { Grid } from '@radix-ui/themes';
import EmploymentInformationForm from "@/Forms/EmploymentInformationForm.jsx";
import SalaryInformationForm from "@/Forms/SalaryInformationForm.jsx";

const EmploymentAndBankTab = ({ user, setUser, departments, designations, allUsers }) => {
    return (
        <Grid columns={{ initial: '1', lg: '2' }} gap="5">
            <EmploymentInformationForm 
                user={user} 
                setUser={setUser} 
                departments={departments} 
                designations={designations} 
                allUsers={allUsers} 
            />
            <SalaryInformationForm user={user} setUser={setUser} />
        </Grid>
    );
};

export default EmploymentAndBankTab;
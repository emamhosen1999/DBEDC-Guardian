import React from 'react';
import { Grid } from '@radix-ui/themes';
import EmploymentInformationForm from "@/Forms/EmploymentInformationForm.jsx";
import SalaryInformationForm from "@/Forms/SalaryInformationForm.jsx";

const EmploymentAndBankTab = ({ user, setUser, departments, designations, allUsers, reportTo, canEdit, canViewCompensation = true, canEditCompensation = canEdit }) => {
    return (
        <Grid columns={{ initial: '1', lg: '2' }} gap="5">
            <EmploymentInformationForm 
                user={user} 
                setUser={setUser} 
                departments={departments} 
                designations={designations} 
                allUsers={allUsers}
                reportTo={reportTo}
                canEdit={canEdit}
            />
            {/* Salary and statutory details: only for someone allowed to see this employee's compensation
                (the server withholds the fields otherwise), and editable only with the compensation permission. */}
            {canViewCompensation && <SalaryInformationForm user={user} setUser={setUser} canEdit={canEditCompensation} />}
        </Grid>
    );
};

export default EmploymentAndBankTab;

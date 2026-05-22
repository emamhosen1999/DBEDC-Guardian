import React, {useState} from 'react';
import {Head, usePage} from "@inertiajs/react";
import App from "@/Layouts/App.jsx";
import CompanyInformationForm from "@/Forms/CompanyInformationForm.jsx";
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';


const CompanySettings = ({title}) => {
    const [settings, setSettings] = useState(usePage().props.companySettings);


    return (
        <>
            <Head title={title}/>
            <div className="flex justify-center p-4">
                <ErrorBoundary>
                    <div
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.6 }}
                    >
                        <CompanyInformationForm settings={settings} setSettings={setSettings} />
                    </div>
                </ErrorBoundary>
            </div>
        </>

    );
};
CompanySettings.layout = (page) => <App>{page}</App>;
export default CompanySettings;


import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// ES module equivalents for __dirname
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Function to read .env.example file and extract APP_VERSION
const getVersionFromEnv = () => {
    try {
        const envPath = path.join(__dirname, '../.env.example');
        const envContent = fs.readFileSync(envPath, 'utf8');

        // Parse .env.example file for APP_VERSION
        const lines = envContent.split('\n');
        for (const line of lines) {
            const trimmedLine = line.trim();
            if (trimmedLine.startsWith('APP_VERSION=')) {
                const version = trimmedLine.split('=')[1].trim();
                return version;
            }
        }

        console.warn('APP_VERSION not found in .env.example file');
        return null;
    } catch (error) {
        console.error('❌ Failed to read .env.example file:', error);
        return null;
    }
};

// Function to update package.json version
const updatePackageJsonVersion = (version) => {
    try {
        const packageJsonPath = path.join(__dirname, '../package.json');
        const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
        
        packageJson.version = version;
        
        fs.writeFileSync(packageJsonPath, JSON.stringify(packageJson, null, 5));
        console.log(`✅ package.json version updated to: ${version}`);
        
        return true;
    } catch (error) {
        console.error('❌ Failed to update package.json version:', error);
        return false;
    }
};

// Main execution
const syncVersion = () => {
    console.log('🔄 Syncing version from .env to package.json...');
    
    const version = getVersionFromEnv();
    if (!version) {
        console.error('❌ Could not read version from .env file');
        process.exit(1);
    }
    
    const updated = updatePackageJsonVersion(version);
    if (updated) {
        console.log('✅ Version sync completed successfully!');
        process.exit(0);
    } else {
        console.error('❌ Failed to sync version');
        process.exit(1);
    }
};

// Convert Windows path to file URL format for comparison
const scriptPath = path.resolve(process.argv[1]);
const scriptUrl = `file:///${scriptPath.replace(/\\/g, '/')}`;

if (import.meta.url === scriptUrl) {
    syncVersion();
}

// ES module exports
export {
    getVersionFromEnv,
    updatePackageJsonVersion,
    syncVersion
};

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import crypto from 'crypto';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Generate build hash from current timestamp and random
const generateBuildHash = () => {
    const timestamp = Date.now().toString();
    const random = crypto.randomBytes(4).toString('hex');
    return crypto.createHash('md5').update(timestamp + random).digest('hex').substring(0, 8);
};

// Get current version from package.json
const getCurrentVersion = () => {
    try {
        const packageJson = JSON.parse(fs.readFileSync('package.json', 'utf8'));
        return packageJson.version || '1.0.0';
    } catch (error) {
        return '1.0.0';
    }
};

// Auto-increment patch version
const incrementVersion = (version) => {
    const parts = version.split('.');
    if (parts.length >= 3) {
        const patch = parseInt(parts[2], 10) + 1;
        return `${parts[0]}.${parts[1]}.${patch}`;
    }
    return version;
};

// Update package.json with new version
const updatePackageJson = (newVersion) => {
    try {
        const packageJsonPath = path.join(__dirname, '../package.json');
        const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
        packageJson.version = newVersion;
        fs.writeFileSync(packageJsonPath, JSON.stringify(packageJson, null, 5));
        console.log(`✅ package.json updated to: ${newVersion}`);
        return true;
    } catch (error) {
        console.error('❌ Failed to update package.json:', error);
        return false;
    }
};

// Update .env with new version
const updateEnvFile = (newVersion) => {
    try {
        const envPath = path.join(__dirname, '../.env');
        let envContent = fs.readFileSync(envPath, 'utf8');
        
        // Check if APP_VERSION exists
        if (envContent.includes('APP_VERSION=')) {
            envContent = envContent.replace(/APP_VERSION=.*/g, `APP_VERSION=${newVersion}`);
        } else {
            envContent += `\nAPP_VERSION=${newVersion}`;
        }
        
        fs.writeFileSync(envPath, envContent);
        console.log(`✅ .env updated to: ${newVersion}`);
        return true;
    } catch (error) {
        console.error('❌ Failed to update .env:', error);
        return false;
    }
};

// Create build metadata file
const createBuildMetadata = (version, buildHash) => {
    try {
        const metadata = {
            version,
            buildHash,
            buildTimestamp: new Date().toISOString(),
            buildDate: new Date().toLocaleDateString()
        };
        
        const metadataPath = path.join(__dirname, '../public/build-metadata.json');
        fs.writeFileSync(metadataPath, JSON.stringify(metadata, null, 2));
        console.log(`✅ Build metadata created: v${version} (${buildHash})`);
        return true;
    } catch (error) {
        console.error('❌ Failed to create build metadata:', error);
        return false;
    }
};

// Main execution
const autoVersion = () => {
    console.log('🔄 Auto-incrementing version for new build...');
    
    const currentVersion = getCurrentVersion();
    const newVersion = incrementVersion(currentVersion);
    const buildHash = generateBuildHash();
    
    console.log(`Current version: ${currentVersion}`);
    console.log(`New version: ${newVersion}`);
    console.log(`Build hash: ${buildHash}`);
    
    const pkgUpdated = updatePackageJson(newVersion);
    const envUpdated = updateEnvFile(newVersion);
    const metadataCreated = createBuildMetadata(newVersion, buildHash);
    
    if (pkgUpdated && envUpdated && metadataCreated) {
        console.log('✅ Auto-version completed successfully!');
        process.exit(0);
    } else {
        console.error('❌ Auto-version failed');
        process.exit(1);
    }
};

const scriptPath = path.resolve(process.argv[1]);
const scriptUrl = `file:///${scriptPath.replace(/\\/g, '/')}`;

if (import.meta.url === scriptUrl) {
    autoVersion();
}

export {
    generateBuildHash,
    getCurrentVersion,
    incrementVersion,
    autoVersion
};

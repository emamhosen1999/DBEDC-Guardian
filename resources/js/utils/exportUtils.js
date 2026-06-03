import axios from 'axios';
import { showToast } from './toastUtils';

/**
 * Polls the download URL until the file is ready (returns 200 OK) or times out.
 * 
 * @param {string} downloadUrl The URL where the file will be available
 * @param {string} filename The name to save the file as
 * @param {number} timeout Total time to poll in milliseconds (default 60 seconds)
 * @param {number} interval Time between polls in milliseconds (default 2 seconds)
 * @returns {Promise<boolean>} Resolves to true if successful, false or throws on error
 */
export const pollExport = async (downloadUrl, filename, timeout = 60000, interval = 2000) => {
  const startTime = Date.now();
  
  return new Promise((resolve, reject) => {
    const check = async () => {
      try {
        // Use a HEAD/GET request to verify file existence without triggering global axios interceptors
        const response = await fetch(downloadUrl, { method: 'HEAD', cache: 'no-store' });
        
        if (!response.ok) {
          throw new Error('Not ready');
        }
        
        // If it succeeds, trigger the browser download
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        link.remove();
        
        resolve(true);
      } catch (error) {
        if (Date.now() - startTime > timeout) {
          reject(new Error('Export timed out. Please try again.'));
          return;
        }
        
        // If it's a 404 (not ready yet) or 403 or network error, wait and check again
        setTimeout(check, interval);
      }
    };
    
    // Start checking
    check();
  });
};

/**
 * Helper to handle queued or direct export responses.
 * 
 * @param {any} responseData Response data from axios
 * @param {string} defaultFilename Filename if none is provided in response
 * @param {string} mime Mime type if direct blob download
 * @param {string} ext Extension if direct blob download
 */
export const handleExportResponse = async (responseData, defaultFilename, mime, ext) => {
  let json = null;
  
  // Detect if the response is a JSON wrapped in a Blob (when responseType: 'blob' is used)
  if (responseData instanceof Blob && responseData.type === 'application/json') {
    try {
      const text = await responseData.text();
      json = JSON.parse(text);
    } catch (e) {
      // Not JSON or failed to parse
    }
  } else if (typeof responseData === 'object' && responseData !== null && !(responseData instanceof Blob)) {
    json = responseData;
  }
  
  if (json && json.queued && json.download_url) {
    showToast.info('Export started in background. Downloading shortly...');
    await pollExport(json.download_url, json.filename || defaultFilename);
    showToast.success('Download complete!');
    return true;
  }
  
  // Standard direct download
  const blob = responseData instanceof Blob ? responseData : new Blob([responseData], mime ? { type: mime } : undefined);
  const blobUrl = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = blobUrl;
  a.download = defaultFilename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(blobUrl);
  return true;
};

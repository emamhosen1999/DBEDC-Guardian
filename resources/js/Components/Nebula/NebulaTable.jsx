import React from 'react';

const NebulaTable = ({ 
  columns = [], 
  data = [], 
  className = '', 
  ...props 
}) => {
  return (
    <div className={`table-shell glass ${className}`} {...props}>
      <table className="ng-table">
        <thead>
          <tr>
            {columns.map((column, index) => (
              <th key={index}>{column.header}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.map((row, rowIndex) => (
            <tr key={rowIndex}>
              {columns.map((column, colIndex) => (
                <td key={colIndex}>
                  {column.render ? column.render(row[column.key], row) : row[column.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default NebulaTable;

#include <iostream>
#include <vector>

using namespace std;

int main() {
    int n;
    
    cin >> n;

    vector<int> arr(n);
 
    for (int i = 0; i < n; ++i) {
        cin >> arr[i];
    }

    long long sum = 0; // Use long long to avoid potential overflow
    for (int i = 0; i < n; ++i) {
        sum += arr[i];
    }

    cout<< sum << endl;

    return 0; // Indicate successful execution
}